/**
 * Supplier feed catalog (MMI_Pipeline_Feed_Catalog) — one script for every
 * supplier's Catalog and Promotions tabs (cfg.view), and for several
 * catalogs shown as one (a group's rows carry `key`, unique across them).
 * Columns and filters come from the section's data-config; the server
 * filters, sorts and pages. The freshness strip is
 * refreshed every 5 s while a fetch runs and every 60 s otherwise, paused
 * while the browser tab is hidden.
 *
 * Compare: up to COMPARE_MAX products ticked in the first column, from any
 * search, filter or page, kept per catalog (both views) and shown side by
 * side above the table: prices with the gap to the first product, our
 * listing, and every feed field, rows that differ highlighted.
 *
 * Links can open the tab on a search (?fc_q=), a row's details
 * (?fc_open=<row key>) or a comparison (?fc_compare=<key>,<key>), as the
 * Data Pipeline's duplicates review does.
 */
(function ($) {
    'use strict';

    const SEARCH_DEBOUNCE_MS = 400;
    const POLL_FETCHING_MS = 5000;
    const POLL_IDLE_MS = 60000;
    // Filters with fixed values (pills in the template); the rest are facets.
    const FIXED_FILTERS = {
        catalog: { promo: ['active', 'upcoming', 'none'], on_site: ['publish', 'other', 'none'] },
        promotions: { promo: ['active', 'upcoming'], check: ['loss', 'above_map', 'ok', 'none'] },
    };
    // Badge text; the Check filter pills spell each one out.
    const CHECKS = {
        loss: ['error', 'Below cost', 'Our price is below the promo cost: a loss.'],
        above_map: ['warning', 'Above MAP', 'Our price is above the promo MAP: the promotion is being missed.'],
        ok: ['success', 'OK', ''],
        none: ['', 'Not listed', 'Not on our site, or no price.'],
    };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // The currency goes in a tooltip, not the cell, so narrow columns fit.
    const money = (value, currency) => (value === null || value === undefined || value === '') ? '—' : `<span title="${esc(currency || '')}">${Number(value).toFixed(2)}</span>`;
    // A feed field that is nothing but a web address is shown as a link.
    const URL_VALUE = /^https?:\/\/[^\s"<>]+$/i;
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    // A feed's Y-m-d as "Oct 27", with the year only when it isn't this year's.
    const shortDate = (ymd) => {
        const [y, m, d] = String(ymd || '').split('-').map(Number);
        if (!y || !m || !d) {
            return '';
        }
        return `${MONTHS[m - 1]} ${d}${y !== new Date().getFullYear() ? `, ${y}` : ''}`;
    };
    const ago = (s) => {
        if (s === null || s === undefined) {
            return '';
        }
        if (s < 90) {
            return `${Math.max(1, Math.round(s))} s`;
        }
        if (s < 5400) {
            return `${Math.round(s / 60)} min`;
        }
        if (s < 172800) {
            return `${Math.round(s / 3600)} h`;
        }
        return `${Math.round(s / 86400)} days`;
    };

    const rowKey = (r) => String(r.key ?? r.sku);

    const COMPARE_MAX = 6;
    // Compared as text: tags gone, spaces collapsed, case ignored.
    const norm = (v) => String(v ?? '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
    const num = (v) => (v === null || v === undefined || v === '' || isNaN(Number(v)) ? null : Number(v));
    // Name words that say nothing about which item it is.
    const NAME_NOISE = new Set(['download', 'esd', 'electronic', 'delivery', 'digital', 'software', 'the', 'by', 'for', 'and', 'of', 'a', 'with', 'plugin', 'plug', 'in', 'ins', 'vst', 'vst3', 'au', 'aax']);
    // Name words that make two otherwise equal names different items.
    const EDITION_WORDS = new Set(['upgrade', 'upg', 'crossgrade', 'xgrade', 'update', 'edu', 'education', 'educational', 'academic', 'student', 'teacher', 'institution', 'institutional', 'nfr', 'competitive', 'bundle', 'collection', 'suite', 'lite', 'le', 'se', 'intro', 'artist', 'standard', 'std', 'pro', 'ultimate', 'complete', 'studio', 'essential', 'essentials', 'expansion', 'addon', 'add', 'subscription', 'perpetual', 'rent', 'rental', 'license', 'licence', 'seat', 'seats', 'user', 'users', 'site', 'multi', 'single', 'full', 'version', 'boxed', 'box']);
    const nameWords = (name, skip) => [...new Set(String(name || '').toLowerCase().replace(/&/g, ' ').split(/[^a-z0-9]+/))]
        .filter((w) => w && !NAME_NOISE.has(w) && !skip.has(w));
    const isEditionWord = (w) => EDITION_WORDS.has(w) || /^v?\d/.test(w);

    function FeedCatalog($root) {
        this.$root = $root;
        this.cfg = $root.data('config');
        this.cfg.view = this.cfg.view || 'catalog';
        this.fixed = FIXED_FILTERS[this.cfg.view];
        this.storageKey = `mmiFeedCatalog_${this.cfg.source}${this.cfg.view === 'catalog' ? '' : `_${this.cfg.view}`}`;
        this.state = { q: '', f: {}, promo: [], on_site: [], check: [], sort: 'product', dir: 'asc', page: 1, per_page: 50 };
        this.facetsLoaded = false;
        this.openSku = null;
        this.request = null;
        this.pollTimer = null;
        this.wasFetching = false;
        this.lastStatus = null;
        // One more column than the config: the compare tick.
        this.span = this.cfg.columns.length + 1;
        this.compareStoreKey = `mmiFeedCatalogCompare_${this.cfg.source}`;
        this.picks = [];
        this.rowsByKey = {};
        this.compareOpen = false;
        this.compareRequest = null;
        this.init();
    }

    FeedCatalog.prototype = {
        $: function (selector) {
            return this.$root.find(selector);
        },

        ajax: function (action, data) {
            return $.post(this.cfg.ajaxUrl, Object.assign({ action: `mmi_feed_catalog_${action}`, nonce: this.cfg.nonce, source: this.cfg.source, view: this.cfg.view }, data || {}));
        },

        init: function () {
            try {
                Object.assign(this.state, JSON.parse(localStorage.getItem(this.storageKey) || '{}'), { page: 1 });
            } catch (e) { /* storage unavailable */ }
            if (!this.cfg.perPage.includes(this.state.per_page)) {
                this.state.per_page = 50;
            }
            // Filters are lists now; a saved single value from before becomes one.
            const asList = (v) => (Array.isArray(v) ? v : (v ? [String(v)] : []));
            Object.keys(this.state.f || {}).forEach((k) => { this.state.f[k] = asList(this.state.f[k]); });
            this.loadPicks();
            this.applyUrlFilters();
            ['promo', 'on_site', 'check'].forEach((key) => {
                this.state[key] = asList(this.state[key]).filter((v) => (this.fixed[key] || []).includes(v));
            });

            // One pager above the table and one below, kept on the same page.
            this.pagers = this.$('.mmi-fc-pager').toArray().map((el) => window.MMIPagination.init({
                container: el,
                totalPages: 1,
                page: 1,
                showFirstLast: true,
                pageSizes: this.cfg.perPage,
                pageSize: this.state.per_page,
                onPageChange: (page) => {
                    // setPage() in syncPagers() fires this too; only a click should load.
                    if (this.syncingPager) {
                        return;
                    }
                    this.state.page = page;
                    this.load(true);
                },
                onPageSizeChange: (size) => { this.state.per_page = size; this.state.page = 1; this.load(true); },
            }));

            let timer = null;
            this.$('.mmi-fc-q').val(this.state.q).on('input', (e) => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    this.state.q = $(e.currentTarget).val().trim();
                    this.state.page = 1;
                    this.load();
                }, SEARCH_DEBOUNCE_MS);
            });
            this.initMultiselects();
            this.$('.mmi-fc-clear').on('click', () => {
                Object.assign(this.state, { q: '', f: {}, promo: [], on_site: [], check: [], page: 1 });
                this.$('.mmi-fc-q').val('');
                this.syncControls();
                this.load();
            });
            this.$('thead').on('click', 'th[data-sort-key]', (e) => {
                const key = $(e.currentTarget).data('sort-key');
                this.state.dir = this.state.sort === key && this.state.dir === 'asc' ? 'desc' : 'asc';
                this.state.sort = key;
                this.state.page = 1;
                this.load();
            });
            this.$('.mmi-fc-tbody').on('click', 'tr.mmi-fc-row', (e) => {
                if ($(e.target).is('a, a *, button, button *, input, .mmi-fc-td-pick') || window.getSelection().toString().length > 0) {
                    return;
                }
                this.toggle(String($(e.currentTarget).data('sku')));
            });
            this.$('.mmi-fc-fetch').on('click', () => this.fetchNow());
            document.addEventListener('visibilitychange', () => this.schedulePoll());
            this.initCompare();

            this.syncControls();
            this.renderPicks();
            this.load();
            if (this.openCompareOnLoad) {
                this.openCompare();
            }
        },

        /**
         * A link can open the table filtered: ?fc[brand][]=Sonnox&fc[check][]=above_map.
         * It replaces the remembered search and filters.
         */
        applyUrlFilters: function () {
            const picked = {};
            const params = new URLSearchParams(window.location.search);
            this.applyUrlActions(params);
            params.forEach((value, name) => {
                // fc[brand][] or, after WordPress rewrites the address, fc[brand][0].
                const m = name.match(/^fc\[([a-z_]+)\]\[\d*\]$/);
                if (m && value !== '') {
                    (picked[m[1]] = picked[m[1]] || []).push(value);
                }
            });
            const q = (params.get('fc_q') || '').trim();
            if (!Object.keys(picked).length && !q) {
                return;
            }
            Object.assign(this.state, { q, f: {}, promo: [], on_site: [], check: [] });
            Object.entries(picked).forEach(([key, values]) => {
                if (key in this.fixed) {
                    this.state[key] = values;
                } else if (key in this.cfg.facets) {
                    this.state.f[key] = values;
                }
            });
        },

        /**
         * One-off link actions: ?fc_open=<row key> opens that row's details
         * once it is on the page; ?fc_compare=<key>,<key> replaces the
         * compare picks and opens the comparison. Both are taken out of the
         * address so a reload doesn't undo what was changed since.
         */
        applyUrlActions: function (params) {
            const open = (params.get('fc_open') || '').trim();
            const compare = (params.get('fc_compare') || '').split(',').map((k) => k.trim()).filter(Boolean);
            if (open) {
                this.openSku = open;
                this.scrollToOpen = true;
            }
            if (compare.length) {
                const known = Object.fromEntries(this.picks.map((p) => [p.key, p]));
                this.picks = [...new Set(compare)].slice(0, COMPARE_MAX).map((key) => known[key] || { key, product: key, source: '', sku: '' });
                this.savePicks();
                this.openCompareOnLoad = true;
            }
            if ((open || compare.length) && window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('fc_open');
                url.searchParams.delete('fc_compare');
                window.history.replaceState(window.history.state, '', url.toString());
            }
        },

        // ── Multi-select filters (shared .mmi-multiselect markup) ──────────
        filterValues: function (key) {
            return (key in this.fixed ? this.state[key] : this.state.f[key]) || [];
        },

        setFilterValues: function (key, values) {
            if (key in this.fixed) {
                this.state[key] = values;
            } else {
                this.state.f[key] = values;
            }
        },

        /** Pills and dropdown options show what the state says (after load, fill or Clear). */
        syncControls: function () {
            this.$('.mmi-fc-filters .mmi-filter-pills[data-filter]').each((i, el) => {
                const chosen = this.filterValues($(el).data('filter'));
                $(el).find('.mmi-pill').each((j, pill) => {
                    const on = chosen.includes(String($(pill).data('value')));
                    $(pill).toggleClass('active', on).attr('aria-pressed', on ? 'true' : 'false');
                });
            });
            this.$('.mmi-fc-ms').each((i, el) => {
                const chosen = this.filterValues($(el).data('filter'));
                $(el).find('.mmi-ms-option').each((j, opt) => {
                    $(opt).toggleClass('is-selected', chosen.includes(String($(opt).data('value'))));
                });
                this.renderMsLabel($(el));
            });
        },

        /** Trigger text: the filter name, the one picked option, or the name with a count badge. */
        renderMsLabel: function ($ms) {
            const $on = $ms.find('.mmi-ms-option.is-selected');
            const name = $ms.data('label');
            $ms.find('.mmi-ms-label').text($on.length === 1 ? `${name}: ${$on.find('.mmi-ms-opt-label').text()}` : name);
            $ms.find('.mmi-ms-badge').text($on.length).prop('hidden', $on.length < 2);
            $ms.toggleClass('has-value', $on.length > 0);
        },

        closeMenus: function ($except) {
            this.$('.mmi-fc-ms').not($except || []).removeClass('is-open')
                .find('.mmi-ms-menu').prop('hidden', true).end()
                .find('.mmi-ms-trigger').attr('aria-expanded', 'false');
        },

        // Pills (short lists) and .mmi-multiselect dropdowns (long lists) —
        // both multi-pick; a burst of picks sends one request.
        initMultiselects: function () {
            let timer = null;
            const changed = (key, values) => {
                this.setFilterValues(key, values);
                this.state.page = 1;
                clearTimeout(timer);
                timer = setTimeout(() => this.load(), 300);
            };
            this.$root.on('click', '.mmi-fc-filters .mmi-filter-pills .mmi-pill', (e) => {
                const $pill = $(e.currentTarget);
                const $group = $pill.closest('.mmi-filter-pills');
                const on = !$pill.hasClass('active');
                $pill.toggleClass('active', on).attr('aria-pressed', on ? 'true' : 'false');
                changed($group.data('filter'), $group.find('.mmi-pill.active').toArray().map((el) => String($(el).data('value'))));
            });
            this.$root.on('click', '.mmi-fc-ms .mmi-ms-trigger', (e) => {
                e.stopPropagation();
                const $ms = $(e.currentTarget).closest('.mmi-fc-ms');
                const open = !$ms.hasClass('is-open');
                this.closeMenus($ms);
                $ms.toggleClass('is-open', open).find('.mmi-ms-menu').prop('hidden', !open);
                $(e.currentTarget).attr('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    $ms.find('.mmi-ms-search:visible').trigger('focus');
                }
            });
            this.$root.on('click', '.mmi-fc-ms .mmi-ms-option', (e) => {
                e.stopPropagation();
                const $ms = $(e.currentTarget).toggleClass('is-selected').closest('.mmi-fc-ms');
                this.renderMsLabel($ms);
                changed($ms.data('filter'), $ms.find('.mmi-ms-option.is-selected').toArray().map((el) => String($(el).data('value'))));
            });
            $(document).on('click', (e) => {
                if (!$(e.target).closest('.mmi-fc-ms').length) {
                    this.closeMenus();
                }
            }).on('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.closeMenus();
                }
            });
            this.$root.on('input', '.mmi-fc-ms .mmi-ms-search', (e) => {
                const term = $(e.currentTarget).val().trim().toLowerCase();
                $(e.currentTarget).closest('.mmi-ms-menu').find('.mmi-ms-option').each((i, el) => {
                    el.hidden = term !== '' && el.textContent.toLowerCase().indexOf(term) === -1;
                });
            });
        },

        remember: function () {
            try {
                const { q, f, promo, on_site, check, sort, dir, per_page } = this.state;
                localStorage.setItem(this.storageKey, JSON.stringify({ q, f, promo, on_site, check, sort, dir, per_page }));
            } catch (e) { /* storage unavailable */ }
        },

        // toTop: a page change scrolls back to the table's first row, since
        // the table is as tall as the page size (no inner scroll box).
        load: function (toTop) {
            this.remember();
            if (this.request) {
                this.request.abort();
            }
            const $tbody = this.$('.mmi-fc-tbody').addClass('mmi-fc-loading');
            this.request = this.ajax('query', this.state).done((res) => {
                if (!res.success) {
                    this.renderEmpty(esc(res.data?.message || 'Could not load the catalog.'));
                    return;
                }
                const d = res.data;
                this.state.page = d.page;
                this.fillFacets(d.facets);
                this.renderRows(d.rows);
                this.$('thead th').removeClass('is-active-asc is-active-desc sorted-asc sorted-desc');
                this.$(`thead th[data-sort-key="${this.state.sort}"]`).addClass(this.state.dir === 'asc' ? 'sorted-asc' : 'sorted-desc');
                this.$('.mmi-fc-count').text(`${d.total.toLocaleString()} of ${d.all.toLocaleString()} ${this.cfg.view === 'promotions' ? 'promotions' : 'products'}`);
                this.$('[data-fc-check-count]').each((i, el) => {
                    el.textContent = (d.checks || {})[el.dataset.fcCheckCount] || 0;
                });
                this.syncingPager = true;
                this.pagers.forEach((pager) => {
                    pager.setTotalPages(d.pages);
                    pager.setPage(d.page);
                });
                this.syncingPager = false;
                // MMIPagination has no page-size setter; a size picked in one
                // pager is shown in the other too.
                this.$('.mmi-fc-pager select').val(String(this.state.per_page));
                this.renderStatus(d.status);
                if (toTop) {
                    const top = this.$('.mmi-fc-table-wrap').offset().top - 60;
                    if (window.scrollY > top) {
                        window.scrollTo({ top, behavior: 'smooth' });
                    }
                }
            }).fail((xhr, textStatus) => {
                if (textStatus !== 'abort') {
                    this.renderEmpty(`Could not load the catalog (HTTP ${xhr.status || 'error'}).`);
                }
            }).always(() => $tbody.removeClass('mmi-fc-loading'));
        },

        fillFacets: function (facets) {
            if (this.facetsLoaded || !facets) {
                return;
            }
            this.facetsLoaded = true;
            // Up to 4 values: pills, like the Reverb filters; more: a dropdown,
            // with a search box once the list passes 8.
            Object.keys(this.cfg.facets).forEach((key) => {
                const $slot = this.$(`.mmi-fc-facet[data-filter="${key}"]`);
                const label = esc($slot.data('label'));
                const values = facets[key] || [];
                if (!values.length) {
                    $slot.empty();
                    return;
                }
                if (values.length <= 4) {
                    $slot.html(`<div class="mmi-filter-group"><span class="mmi-filter-label">${label}</span><div class="mmi-filter-pills" data-filter="${esc(key)}">${values.map((v) => `<button type="button" class="mmi-pill" data-value="${esc(v)}" aria-pressed="false">${esc(v)}</button>`).join('')}</div></div>`);
                    return;
                }
                $slot.html(`<div class="mmi-multiselect mmi-fc-ms" data-filter="${esc(key)}" data-label="${label}">
                    <button type="button" class="mmi-ms-trigger" aria-expanded="false"><span class="mmi-ms-label">${label}</span><span class="mmi-ms-badge" hidden></span><span class="mmi-ms-caret">▾</span></button>
                    <div class="mmi-ms-menu" hidden>
                        ${values.length > 8 ? `<input type="search" class="mmi-ms-search" placeholder="Filter…" aria-label="Filter ${label}">` : ''}
                        ${values.map((v) => `<button type="button" class="mmi-ms-option" data-value="${esc(v)}"><span class="mmi-ms-opt-label">${esc(v)}</span></button>`).join('')}
                    </div>
                </div>`);
            });
            this.syncControls();
        },

        renderEmpty: function (html) {
            this.$('.mmi-fc-tbody').html(`<tr><td colspan="${this.span}" class="mmi-fc-empty">${html}</td></tr>`);
        },

        cell: function (col, r) {
            const v = r[col.key];
            switch (col.type) {
                case 'sku':
                    return esc(r.sku);
                case 'code':
                    return v ? `<span title="${esc(v)}">${esc(v)}</span>` : '—';
                case 'product': {
                    const thumb = r.image
                        ? `<img class="mmi-fc-thumb" src="${esc(r.image)}" alt="" loading="lazy">`
                        : '<span class="mmi-fc-thumb mmi-fc-thumb--none" aria-hidden="true"></span>';
                    return `<div class="mmi-fc-product">${thumb}<div class="mmi-fc-name"><span>${esc(r.product)}</span>${r.sub ? `<span class="mmi-fc-sub">${esc(r.sub)}</span>` : ''}</div></div>`;
                }
                case 'money':
                    return money(v, r.currency);
                case 'date':
                    return v ? `<span title="${esc(v)}">${esc(String(v).slice(0, 10))}</span>` : '—';
                case 'promo':
                    return r.promo
                        ? `${money(r.promo.price, r.currency)} <span class="mmi-badge ${r.promo.state === 'active' ? 'success' : 'info'}" title="${esc(r.promo.name ? r.promo.name + ': ' : '')}${esc(r.promo.start || 'now')} to ${esc(r.promo.end || 'open')}">${r.promo.state === 'active' ? 'now' : 'from ' + esc(r.promo.start)}</span>`
                        : '—';
                case 'site':
                    return r.site_id
                        ? `<span class="mmi-badge ${r.site_status === 'publish' ? 'success' : 'warning'}">${r.site_status === 'publish' ? 'Published' : esc(r.site_status)}</span>`
                        : '<span class="mmi-fc-muted">Not listed</span>';
                // Promotions view.
                case 'window': {
                    // Running is the usual case; only an upcoming one is marked.
                    const p = r.promo || {};
                    const span = `${esc(shortDate(p.start) || 'now')} – ${esc(shortDate(p.end) || 'open')}`;
                    const title = `${p.name ? `${p.name}: ` : ''}${p.start || 'now'} to ${p.end || 'open'}`;
                    return `${p.state === 'upcoming' ? '<span class="mmi-badge info">Upcoming</span> ' : ''}<span title="${esc(title)}">${span}</span>`;
                }
                case 'promo_pair': {
                    // promo_map / promo_cost: the regular price, then the promotional one.
                    const field = col.key.replace('promo_', '');
                    const p = r.promo || {};
                    const regular = p[`regular_${field}`] ?? r[field];
                    return `${money(regular, r.currency)} → <strong>${money(p[field], r.currency)}</strong>`;
                }
                case 'ours': {
                    if (!r.site_id) {
                        return '<span class="mmi-fc-muted">Not listed</span>';
                    }
                    const price = r.edit_url ? `<a href="${esc(r.edit_url)}" target="_blank" rel="noopener">${money(r.our_price)}</a>` : money(r.our_price);
                    const status = r.site_status !== 'publish' ? ` <span class="mmi-badge warning">${esc(r.site_status)}</span>` : '';
                    return `${price}${status}${r.in_stock ? '' : ' <span class="mmi-fc-sub">Out of stock</span>'}`;
                }
                case 'check': {
                    const [tone, label, title] = CHECKS[r.check] || ['', '', ''];
                    return r.check === 'none'
                        ? `<span class="mmi-fc-muted" title="${esc(title)}">${label}</span>`
                        : `<span class="mmi-badge ${tone}" title="${esc(title)}">${label}</span>`;
                }
                default:
                    // Full text on hover where a narrow column cuts it off.
                    return v === null || v === undefined || v === '' ? '—' : `<span title="${esc(v)}">${esc(v)}</span>`;
            }
        },

        renderRows: function (rows) {
            if (!rows.length) {
                this.renderEmpty('No products match these filters.');
                return;
            }
            const cols = this.cfg.columns;
            this.rowsByKey = {};
            this.$('.mmi-fc-tbody').html(rows.map((r) => {
                const key = rowKey(r);
                this.rowsByKey[key] = r;
                const open = this.openSku === key;
                const pick = `<td class="mmi-fc-td-pick"><input type="checkbox" class="mmi-fc-pick" value="${esc(key)}" aria-label="${esc(`Compare ${r.product || r.sku}`)}"></td>`;
                const tds = cols.map((c) => `<td class="mmi-fc-td-${c.type}">${this.cell(c, r)}</td>`).join('');
                return `<tr class="mmi-fc-row${open ? ' is-open' : ''}" data-sku="${esc(key)}">${pick}${tds}</tr>${open ? this.detailRowHtml(key) : ''}`;
            }).join(''));
            this.syncPickBoxes();
            if (this.openSku && this.$('.mmi-fc-detail-row').length) {
                this.loadDetail(this.openSku);
                if (this.scrollToOpen) {
                    this.scrollToOpen = false;
                    this.$('tr.mmi-fc-row.is-open')[0].scrollIntoView({ block: 'center' });
                }
            }
        },

        detailRowHtml: function (sku) {
            return `<tr class="mmi-fc-detail-row" data-sku="${esc(sku)}"><td colspan="${this.span}"><div class="mmi-fc-detail"><p class="mmi-fc-empty"><span class="mmi-loading"></span> Loading…</p></div></td></tr>`;
        },

        toggle: function (sku) {
            const wasOpen = this.openSku === sku;
            this.$('.mmi-fc-detail-row').remove();
            this.$('tr.mmi-fc-row').removeClass('is-open');
            this.openSku = wasOpen ? null : sku;
            if (!wasOpen) {
                this.$(`tr.mmi-fc-row[data-sku="${CSS.escape(sku)}"]`).addClass('is-open').after(this.detailRowHtml(sku));
                this.loadDetail(sku);
            }
        },

        loadDetail: function (sku) {
            this.ajax('detail', { sku }).done((res) => {
                const $box = this.$(`.mmi-fc-detail-row[data-sku="${CSS.escape(sku)}"] .mmi-fc-detail`);
                $box.html(res.success ? this.detailHtml(res.data) : `<p class="mmi-fc-empty">${esc(res.data?.message || 'Could not load this product.')}</p>`);
                // Links in the feed's own text leave the catalog open.
                $box.find('a[href^="http"]').attr({ target: '_blank', rel: 'noopener noreferrer' });
            });
        },

        // Text sections and list items arrive sanitized (wp_kses_post);
        // everything else is escaped here.
        detailHtml: function (d) {
            const site = d.site
                ? `<a class="button button-small" href="${esc(d.site.edit_url)}" target="_blank" rel="noopener">Edit on our site</a>
                   <a class="button button-small" href="${esc(d.site.view_url)}" target="_blank" rel="noopener">View</a>
                   <span class="mmi-badge ${d.site.status === 'publish' ? 'success' : 'warning'}">${esc(d.site.status)}</span>`
                : '<span class="mmi-fc-muted">Not listed on our site</span>';
            const gallery = d.images.length
                ? `<div class="mmi-fc-gallery">${d.images.slice(0, 10).map((src) => `<a href="${esc(src)}" target="_blank" rel="noopener"><img src="${esc(src)}" alt="" loading="lazy"></a>`).join('')}</div>`
                : '';
            const imagesNote = d.images_note ? `<p class="mmi-fc-muted">${esc(d.images_note)}</p>` : '';
            const text = d.text.map((t) => `<div class="mmi-fc-block"><h4>${esc(t.label)}</h4>${t.html}</div>`).join('');
            const lists = d.lists.map((l) => `<div class="mmi-fc-block"><h4>${esc(l.label)}</h4><ul>${l.items.map((i) => `<li>${i}</li>`).join('')}</ul></div>`).join('');
            const promos = this.promosHtml(d.promos);
            const fields = Object.entries(d.fields).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${URL_VALUE.test(v) ? `<a href="${esc(v)}">${esc(v)}</a>` : esc(v)}</td></tr>`).join('');
            const main = text || lists ? `${text}` : '<p class="mmi-fc-muted">No description in this feed.</p>';
            return `<div class="mmi-fc-detail-head"><strong>${esc(d.product)}</strong><span class="mmi-fc-sub">${esc(d.sku)}</span><div class="mmi-fc-detail-actions">${site}</div></div>
                ${gallery}${imagesNote}
                <div class="mmi-fc-columns"><div>${main}</div><div>${lists}</div></div>
                ${promos}
                <details class="mmi-fc-fields"><summary>Every feed field</summary><table class="mmi-fc-mini"><tbody>${fields}</tbody></table></details>`;
        },

        // Promotions in the shared shape (MMI_Pipeline_Feed_Catalog::promotions()),
        // full width. A column every promotion leaves empty is left out, so
        // one table serves every supplier's feed.
        promosHtml: function (promos) {
            if (!promos.length) {
                return '';
            }
            const states = { active: ['success', 'Running'], upcoming: ['info', 'Upcoming'], ended: ['', 'Ended'] };
            const cols = [
                ['Promotion', (p) => p.name || p.code, (p) => `${esc(p.name)}${p.code ? ` <span class="mmi-fc-sub">${esc(p.code)}</span>` : ''}`],
                ['State', (p) => p.state, (p) => `<span class="mmi-badge ${states[p.state]?.[0] || ''}">${esc(states[p.state]?.[1] || p.state)}</span>`],
                ['From', (p) => p.start, (p) => esc(p.start)],
                ['To', (p) => p.end, (p) => esc(p.end)],
                ['Promo cost', (p) => p.cost !== null, (p) => money(p.cost)],
                ['Regular cost', (p) => p.regular_cost !== null, (p) => money(p.regular_cost)],
                ['Promo MAP', (p) => p.map !== null, (p) => money(p.map)],
                ['Regular MAP', (p) => p.regular_map !== null, (p) => money(p.regular_map)],
            ].filter(([, has]) => promos.some(has));
            const money_col = (label) => /cost|MAP/.test(label) ? ' class="mmi-fc-td-money"' : '';
            return `<div class="mmi-fc-block mmi-fc-promos"><h4>Promotions</h4><table class="mmi-fc-mini">
                <thead><tr>${cols.map(([label]) => `<th${money_col(label)}>${label}</th>`).join('')}</tr></thead>
                <tbody>${promos.map((p) => `<tr>${cols.map(([label, , cell]) => `<td${money_col(label)}>${cell(p)}</td>`).join('')}</tr>`).join('')}</tbody>
            </table></div>`;
        },

        // ── Compare ─────────────────────────────────────────────────────────
        loadPicks: function () {
            try {
                const saved = JSON.parse(localStorage.getItem(this.compareStoreKey) || '[]');
                this.picks = Array.isArray(saved) ? saved.filter((p) => p && typeof p.key === 'string').slice(0, COMPARE_MAX) : [];
            } catch (e) {
                this.picks = [];
            }
        },

        savePicks: function () {
            try {
                localStorage.setItem(this.compareStoreKey, JSON.stringify(this.picks));
            } catch (e) { /* storage unavailable */ }
        },

        isPicked: function (key) {
            return this.picks.some((p) => p.key === key);
        },

        initCompare: function () {
            this.$('.mmi-fc-tbody').on('change', '.mmi-fc-pick', (e) => this.setPick(e.currentTarget.value, e.currentTarget.checked));
            // The whole cell is the target, not just the box.
            this.$('.mmi-fc-tbody').on('click', '.mmi-fc-td-pick', (e) => {
                if (!$(e.target).is('input')) {
                    $(e.currentTarget).find('.mmi-fc-pick:enabled').trigger('click');
                }
            });
            this.$('.mmi-fc-compare-chips').on('click', '.mmi-fc-chip-remove', (e) => this.setPick(String($(e.currentTarget).data('key')), false));
            this.$('.mmi-fc-compare-open').on('click', () => this.openCompare());
            this.$('.mmi-fc-compare-clear').on('click', () => {
                this.picks = [];
                this.savePicks();
                this.renderPicks();
                this.closeCompare();
            });
            this.$('.mmi-fc-compare-close').on('click', () => this.closeCompare());
            this.$('.mmi-fc-compare-only-diff').on('change', () => this.applyOnlyDiff());
            this.$('.mmi-fc-compare-body').on('click', '.mmi-fc-cmp-remove', (e) => this.setPick(String($(e.currentTarget).data('key')), false));
            this.$('.mmi-fc-compare-body').on('click', '.mmi-fc-cmp-first', (e) => {
                const key = String($(e.currentTarget).data('key'));
                this.picks.sort((a, b) => (a.key === key ? -1 : (b.key === key ? 1 : 0)));
                this.savePicks();
                this.renderPicks();
                this.loadCompare();
            });
        },

        setPick: function (key, on) {
            if (on && !this.isPicked(key)) {
                if (this.picks.length >= COMPARE_MAX) {
                    this.syncPickBoxes();
                    return;
                }
                const r = this.rowsByKey[key] || {};
                this.picks.push({ key, product: r.product || key, source: r.source || '', sku: r.sku || '' });
            } else if (!on) {
                this.picks = this.picks.filter((p) => p.key !== key);
            }
            this.savePicks();
            this.renderPicks();
            if (this.compareOpen) {
                if (this.picks.length) {
                    this.loadCompare();
                } else {
                    this.closeCompare();
                }
            }
        },

        /** The ticks in the table follow the picks; a full set disables the rest. */
        syncPickBoxes: function () {
            const full = this.picks.length >= COMPARE_MAX;
            this.$('.mmi-fc-pick').each((i, box) => {
                const on = this.isPicked(box.value);
                box.checked = on;
                box.disabled = full && !on;
                box.title = full && !on ? `Up to ${COMPARE_MAX} products: remove one to add another` : '';
                $(box).closest('tr').toggleClass('is-picked', on);
            });
        },

        /** The bar under the table: one chip per pick, kept while searching and paging. */
        renderPicks: function () {
            const n = this.picks.length;
            this.$('.mmi-fc-compare-bar').prop('hidden', n === 0);
            this.$('.mmi-fc-compare-count').text(`${n} of ${COMPARE_MAX}`);
            this.$('.mmi-fc-compare-open').prop('disabled', n < 2);
            this.$('.mmi-fc-compare-chips').html(this.picks.map((p) => {
                const label = [p.product, p.source].filter(Boolean).join(' · ');
                return `<span class="mmi-fc-chip" title="${esc(`${label}${p.sku ? ` · SKU ${p.sku}` : ''}`)}"><span class="mmi-fc-chip-label">${esc(label)}</span><button type="button" class="mmi-fc-chip-remove" data-key="${esc(p.key)}" aria-label="${esc(`Remove ${p.product} from the comparison`)}">×</button></span>`;
            }).join(''));
            this.syncPickBoxes();
        },

        openCompare: function () {
            if (!this.picks.length) {
                return;
            }
            this.compareOpen = true;
            this.$('.mmi-fc-compare').prop('hidden', false);
            this.loadCompare();
            const top = this.$('.mmi-fc-compare').offset().top - 50;
            window.scrollTo({ top, behavior: 'smooth' });
        },

        closeCompare: function () {
            this.compareOpen = false;
            if (this.compareRequest) {
                this.compareRequest.abort();
            }
            this.$('.mmi-fc-compare').prop('hidden', true);
            this.$('.mmi-fc-compare-body').empty();
        },

        loadCompare: function () {
            if (this.compareRequest) {
                this.compareRequest.abort();
            }
            const $body = this.$('.mmi-fc-compare-body');
            if (!$body.children().length) {
                $body.html('<p class="mmi-fc-empty"><span class="mmi-loading"></span> Loading…</p>');
            }
            $body.addClass('mmi-fc-loading');
            this.compareRequest = this.ajax('compare', { keys: this.picks.map((p) => p.key) }).done((res) => {
                if (!res.success) {
                    $body.html(`<p class="mmi-fc-empty">${esc(res.data?.message || 'Could not load the comparison.')}</p>`);
                    return;
                }
                // Labels from the feed, for picks that came in by link.
                res.data.items.forEach((it) => {
                    const p = this.picks.find((x) => x.key === it.key);
                    if (p && it.row) {
                        Object.assign(p, { product: it.row.product || p.product, source: it.row.source || p.source, sku: it.row.sku || p.sku });
                    }
                });
                this.savePicks();
                this.renderPicks();
                $body.html(this.compareHtml(res.data.items));
                $body.find('.mmi-fc-cmp-text a[href^="http"], .mmi-fc-cmp-list a[href^="http"]').attr({ target: '_blank', rel: 'noopener noreferrer' });
                this.applyOnlyDiff();
            }).fail((xhr, textStatus) => {
                if (textStatus !== 'abort') {
                    $body.html(`<p class="mmi-fc-empty">Could not load the comparison (HTTP ${xhr.status || 'error'}).</p>`);
                }
            }).always(() => $body.removeClass('mmi-fc-loading'));
        },

        /** "Only differences" hides equal rows, and a section left with none. */
        applyOnlyDiff: function () {
            const only = this.$('.mmi-fc-compare-only-diff').prop('checked');
            const $rows = this.$('.mmi-fc-cmp > tbody > tr');
            $rows.filter('.is-same, .is-solo').prop('hidden', only);
            $rows.filter('.mmi-fc-cmp-section').each((i, el) => {
                const $own = $(el).nextUntil('.mmi-fc-cmp-section');
                el.hidden = $own.length > 0 && $own.toArray().every((r) => r.hidden);
            });
        },

        /**
         * The comparison table: one column per product, the first being
         * what the others are measured against (name words, price gaps).
         * A row is `is-diff` when its values aren't all the same, `is-same`
         * when they are (hidden by "Only differences"); `is-solo` for a feed
         * field only one product has (hidden too); `always` rows are
         * neither (the name check, pictures, links).
         */
        compareHtml: function (items) {
            const found = items.filter((it) => !it.missing);
            const missing = items.filter((it) => it.missing);
            const lost = missing.length
                ? `<div class="notice notice-warning inline mmi-fc-cmp-missing"><p>No longer in the feed: ${missing.map((it) => `<code>${esc(it.key)}</code> <button type="button" class="button-link mmi-fc-cmp-remove" data-key="${esc(it.key)}">Remove</button>`).join(', ')}</p></div>`
                : '';
            if (!found.length) {
                return `${lost}<p class="mmi-fc-empty">Nothing to compare.</p>`;
            }
            const rows = found.map((it) => it.row);
            const details = found.map((it) => it.detail || { fields: {}, text: [], lists: [], images: [] });
            const out = [];
            const section = (title) => out.push({ section: title });
            // cells: html per product; values: what's compared (default: the html as text).
            const add = (label, cells, opts = {}) => {
                const values = opts.values || cells;
                const filled = values.map(norm);
                const kind = opts.always ? 'always' : (opts.solo ? 'is-solo' : (new Set(filled).size > 1 ? 'is-diff' : 'is-same'));
                out.push({ label, cells, kind, title: opts.title || '' });
            };
            const first = rows[0];

            // Same item? Name words against the first product's.
            if (rows.length > 1) {
                const skip = new Set();
                rows.forEach((r) => String(r.brand || '').toLowerCase().split(/[^a-z0-9]+/).forEach((w) => w && skip.add(w)));
                const base = nameWords(first.product, skip);
                section('Same item?');
                add('Name words', rows.map((r, i) => {
                    if (i === 0) {
                        return '<span class="mmi-fc-muted">The others are measured against this one.</span>';
                    }
                    const words = nameWords(r.product, skip);
                    const shared = words.filter((w) => base.includes(w)).length;
                    const score = words.length + base.length ? (2 * shared) / (words.length + base.length) : 1;
                    const only = words.filter((w) => !base.includes(w));
                    const lacks = base.filter((w) => !words.includes(w));
                    const edition = [...only, ...lacks].some(isEditionWord);
                    const tone = edition ? 'warning' : (score >= 0.8 ? 'success' : (score >= 0.5 ? 'warning' : 'error'));
                    const list = (ws) => ws.map((w) => (isEditionWord(w) ? `<strong class="mmi-fc-cmp-edition">${esc(w)}</strong>` : esc(w))).join(', ');
                    return `<span class="mmi-badge ${tone}">${Math.round(score * 100)}% of words match</span>`
                        + (only.length ? `<span class="mmi-fc-cmp-words">Only here: ${list(only)}</span>` : '')
                        + (lacks.length ? `<span class="mmi-fc-cmp-words">Not here: ${list(lacks)}</span>` : '')
                        + (edition ? '<span class="mmi-fc-cmp-words mmi-fc-cmp-edition">Edition, upgrade or version words differ</span>' : '');
                }), { always: true, title: 'Brand words and words like "download" are left out. Words that change the edition (upgrade, EDU, Pro, a version number…) are in bold.' });
            }

            // Prices: each against the first product.
            const gap = (v, b) => {
                if (v === null || b === null || b <= 0) {
                    return '';
                }
                const d = v - b;
                if (Math.abs(d) < 0.005) {
                    return '<span class="mmi-fc-cmp-gap">same as first</span>';
                }
                return `<span class="mmi-fc-cmp-gap ${d > 0 ? 'is-up' : 'is-down'}">${d > 0 ? '+' : '−'}${Math.abs(d).toFixed(2)} (${d > 0 ? '+' : '−'}${Math.abs(Math.round((d / b) * 100))}%)</span>`;
            };
            const moneyRow = (label, get, opts = {}) => {
                const vals = rows.map((r) => num(get(r)));
                const present = vals.filter((v) => v !== null);
                const low = opts.lowest && present.length > 1 && new Set(present).size > 1 ? Math.min(...present) : null;
                add(label, vals.map((v, i) => (v === null ? '—'
                    : `<strong>${money(v, rows[i].currency)}</strong>${low !== null && v === low ? ' <span class="mmi-badge success">lowest</span>' : ''}${i > 0 ? gap(v, vals[0]) : ''}`)),
                { values: vals.map((v) => (v === null ? '' : v.toFixed(2))), title: opts.title || '' });
            };
            section('Prices');
            moneyRow('Cost', (r) => r.cost, { lowest: true, title: 'Our regular cost from the distributor, before any promotion.' });
            moneyRow('MAP', (r) => r.map, { title: 'Regular MAP, before any promotion.' });
            moneyRow('MSRP', (r) => r.msrp);
            add('Promotion', rows.map((r) => (r.promo
                ? `${money(r.promo.price, r.currency)} <span class="mmi-badge ${r.promo.state === 'active' ? 'success' : 'info'}">${r.promo.state === 'active' ? 'now' : `from ${esc(shortDate(r.promo.start) || r.promo.start)}`}</span>${r.promo.end ? ` <span class="mmi-fc-sub">to ${esc(shortDate(r.promo.end) || r.promo.end)}</span>` : ''}${r.promo.name ? `<span class="mmi-fc-cmp-words">${esc(r.promo.name)}</span>` : ''}`
                : '—')), { values: rows.map((r) => (r.promo ? `${r.promo.price}|${r.promo.state}|${r.promo.end}` : '')) });
            // Margin at MAP on what we'd pay today (a running promotion's cost).
            const margins = rows.map((r) => {
                const map = num(r.map);
                const cost = r.promo && r.promo.state === 'active' && num(r.promo.price) !== null ? num(r.promo.price) : num(r.cost);
                return map && cost !== null ? { amount: map - cost, pct: ((map - cost) / map) * 100 } : null;
            });
            add('Margin at MAP', margins.map((m) => (m ? `${money(m.amount)} <span class="mmi-fc-sub">${Math.round(m.pct)}%</span>` : '—')),
                { values: margins.map((m) => (m ? m.amount.toFixed(2) : '')), title: 'MAP minus what we pay today (a running promotion\'s cost, else the regular cost).' });

            section('On our site');
            add('Listing', rows.map((r) => (r.site_id
                ? `<span class="mmi-badge ${r.site_status === 'publish' ? 'success' : 'warning'}">${r.site_status === 'publish' ? 'Published' : esc(r.site_status)}</span> <span class="mmi-fc-sub">#${esc(r.site_id)}</span>`
                : '<span class="mmi-fc-muted">Not listed</span>')), { values: rows.map((r) => (r.site_id ? `${r.site_id}|${r.site_status}` : '')) });
            moneyRow('Our price', (r) => r.our_price);
            add('Our stock', rows.map((r) => (!r.site_id ? '—' : (r.our_stock === 'instock' ? 'In stock' : esc(r.our_stock || '—')))));
            add('Links', rows.map((r) => (r.site_id
                ? `${r.edit_url ? `<a href="${esc(r.edit_url)}" target="_blank" rel="noopener">Edit</a>` : ''}${r.view_url ? ` · <a href="${esc(r.view_url)}" target="_blank" rel="noopener">View</a>` : ''}`
                : '—')), { always: true });

            // The table's own columns, then every feed field by name.
            section('Feed');
            add('SKU', rows.map((r) => esc(r.sku)));
            const shown = new Set(['sku', 'product', 'cost', 'map', 'msrp', 'promo', 'site']);
            this.cfg.columns.filter((c) => !shown.has(c.key) && ['text', 'code', 'date', 'money'].includes(c.type)).forEach((c) => {
                if (c.type === 'money') {
                    moneyRow(c.label, (r) => r[c.key], { title: c.title });
                } else {
                    add(c.label, rows.map((r) => (r[c.key] === null || r[c.key] === undefined || r[c.key] === '' ? '—' : esc(r[c.key]))), { title: c.title });
                }
            });
            const labels = [];
            details.forEach((d) => Object.keys(d.fields || {}).forEach((k) => {
                if (!labels.includes(k) && k !== 'Distributor') {
                    labels.push(k);
                }
            }));
            // Distributors name their fields differently: a field only one
            // product has can't disagree, so those come last, unhighlighted.
            const has = (d, k) => (d.fields || {})[k] !== undefined && (d.fields || {})[k] !== '';
            const shared = details.length > 1 ? labels.filter((k) => details.filter((d) => has(d, k)).length > 1) : labels;
            const solo = labels.filter((k) => !shared.includes(k));
            const fieldRow = (k, opts) => add(k, details.map((d) => {
                const v = (d.fields || {})[k];
                if (!has(d, k)) {
                    return '—';
                }
                return URL_VALUE.test(v) ? `<a href="${esc(v)}" target="_blank" rel="noopener noreferrer">${esc(v.replace(/^https?:\/\//, ''))}</a>` : esc(v);
            }), opts);
            shared.forEach((k) => fieldRow(k));
            if (solo.length) {
                section('In one feed only');
                solo.forEach((k) => fieldRow(k, { solo: true }));
            }

            section('Content');
            add('Pictures', details.map((d) => ((d.images || []).length
                ? `<div class="mmi-fc-cmp-gallery">${d.images.slice(0, 6).map((src) => `<a href="${esc(src)}" target="_blank" rel="noopener"><img src="${esc(src)}" alt="" loading="lazy"></a>`).join('')}</div>`
                : '<span class="mmi-fc-muted">None in the feed</span>')), { always: true });
            add('Description', details.map((d) => ((d.text || []).length
                ? `<div class="mmi-fc-cmp-text">${d.text.map((t) => `<h5>${esc(t.label)}</h5>${t.html}`).join('')}</div>`
                : '—')));
            const listLabels = [];
            details.forEach((d) => (d.lists || []).forEach((l) => {
                if (!listLabels.includes(l.label)) {
                    listLabels.push(l.label);
                }
            }));
            listLabels.forEach((label) => add(label, details.map((d) => {
                const l = (d.lists || []).find((x) => x.label === label);
                return l ? `<ul class="mmi-fc-cmp-list">${l.items.map((i) => `<li>${i}</li>`).join('')}</ul>` : '—';
            })));

            const head = rows.map((r, i) => {
                const img = r.image || (details[i].images || [])[0];
                return `<th scope="col"><div class="mmi-fc-cmp-card">
                    <div class="mmi-fc-cmp-img">${img ? `<img src="${esc(img)}" alt="" loading="lazy">` : '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>'}</div>
                    <strong class="mmi-fc-cmp-name">${esc(r.product)}</strong>
                    <span class="mmi-fc-sub">${esc([r.source, r.brand, `SKU ${r.sku}`].filter(Boolean).join(' · '))}</span>
                    <span class="mmi-fc-cmp-actions">${i === 0 ? '<span class="mmi-badge info">First</span>' : `<button type="button" class="button-link mmi-fc-cmp-first" data-key="${esc(found[i].key)}">Measure against this</button>`}<button type="button" class="button-link mmi-fc-cmp-remove" data-key="${esc(found[i].key)}">Remove</button></span>
                </div></th>`;
            }).join('');
            const body = out.map((row) => (row.section
                ? `<tr class="mmi-fc-cmp-section"><th colspan="${rows.length + 1}" scope="colgroup">${esc(row.section)}</th></tr>`
                : `<tr class="${row.kind}"><th scope="row"${row.title ? ` title="${esc(row.title)}"` : ''}>${esc(row.label)}</th>${row.cells.map((c) => `<td>${c}</td>`).join('')}</tr>`)).join('');
            const diffs = out.filter((r) => r.kind === 'is-diff').length;
            const summary = rows.length > 1
                ? `<p class="mmi-fc-cmp-summary">${diffs ? `${diffs} row${diffs === 1 ? '' : 's'} differ.` : 'Every compared row is the same.'}</p>`
                : '<p class="mmi-fc-cmp-summary">Tick another product in the table to compare it with this one.</p>';
            return `${lost}${summary}<div class="mmi-fc-cmp-wrap"><table class="mmi-fc-cmp" style="--mmi-fc-cmp-cols:${rows.length}">
                <thead><tr><th scope="col" class="mmi-fc-cmp-corner">${rows.length} of ${COMPARE_MAX}</th>${head}</tr></thead>
                <tbody>${body}</tbody></table></div>`;
        },

        // ── Freshness strip + Fetch now ─────────────────────────────────────
        renderStatus: function (s) {
            if (!s) {
                return;
            }
            this.$('.mmi-fc-files').html(s.files.map((f) => {
                const band = f.band;
                const count = f.count !== null && f.count !== undefined ? ` · ${Number(f.count).toLocaleString()}` : '';
                return `<span class="mmi-fc-file mmi-fc-file--${band}" title="${esc(f.at || 'never fetched')}"><span class="mmi-fc-dot" aria-hidden="true"></span><strong>${esc(f.label)}</strong> <span class="mmi-fc-age">${f.age === null ? 'never fetched' : esc(ago(f.age)) + ' ago'}${esc(count)}</span></span>`;
            }).join(''));

            let state;
            if (!s.can_fetch) {
                state = 'Fetching isn\'t available on this site.';
            } else if (s.feed_run && s.feed_run.steps.length) {
                state = `<span class="mmi-fc-spinner" aria-hidden="true"></span> Fetching ${esc(s.feed_run.step)} (step ${s.feed_run.index + 1} of ${s.feed_run.steps.length})…`;
            } else if (s.feed_run) {
                state = '<span class="mmi-fc-spinner" aria-hidden="true"></span> A fetch is in progress…';
            } else if (s.cooldown > 0) {
                state = `Fetched ${esc(ago(this.cfg.cooldown - s.cooldown))} ago. The next fetch is allowed in ${esc(ago(s.cooldown))}.`;
            } else if (s.blocked) {
                state = `<span class="mmi-fc-error">${esc(s.blocked)}</span>`;
            } else {
                state = s.next_at ? `Next automatic fetch ${esc(s.next_at)}.` : esc(s.note || '');
            }
            (s.extra || []).forEach((e) => {
                state += ` <span class="mmi-fc-extra${e.busy ? ' is-busy' : ''}">${e.busy ? '<span class="mmi-fc-spinner" aria-hidden="true"></span>' : ''}${esc(e.text)}</span>`;
            });
            this.$('.mmi-fc-fetch-state').html(state);
            this.$('.mmi-fc-fetch').prop('disabled', !s.can_fetch || !!s.feed_run || s.cooldown > 0 || !!s.blocked);

            // A fetch just finished: the files changed, so reload the rows
            // (the server rebuilds its index on the first query).
            if (this.wasFetching && !s.fetching) {
                this.facetsLoaded = false;
                this.load();
            }
            this.wasFetching = !!s.fetching;
            this.lastStatus = s;
            this.schedulePoll();
        },

        schedulePoll: function () {
            clearTimeout(this.pollTimer);
            if (document.hidden) {
                return;
            }
            const ms = this.lastStatus && this.lastStatus.fetching ? POLL_FETCHING_MS : POLL_IDLE_MS;
            this.pollTimer = setTimeout(() => {
                this.ajax('status').done((res) => {
                    if (res.success) {
                        this.renderStatus(res.data);
                    } else {
                        this.schedulePoll();
                    }
                }).fail(() => this.schedulePoll());
            }, ms);
        },

        fetchNow: function () {
            const $btn = this.$('.mmi-fc-fetch').prop('disabled', true).addClass('mmi-is-loading');
            this.ajax('fetch_now').done((res) => {
                if (res.data && res.data.status) {
                    this.renderStatus(res.data.status);
                }
                if (!res.success) {
                    this.$('.mmi-fc-fetch-state').html(`<span class="mmi-fc-error">${esc(res.data?.message || 'Could not start a fetch.')}</span>`);
                }
            }).fail(() => {
                this.$('.mmi-fc-fetch-state').html('<span class="mmi-fc-error">Network error: the fetch may not have started.</span>');
                $btn.prop('disabled', false);
            }).always(() => $btn.removeClass('mmi-is-loading'));
        },
    };

    $(() => $('.mmi-fc[data-config]').each((i, el) => new FeedCatalog($(el))));
}(jQuery));

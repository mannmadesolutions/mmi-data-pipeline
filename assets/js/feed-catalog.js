/**
 * Supplier feed catalog (MMI_Pipeline_Feed_Catalog) — one script for every
 * supplier's Catalog tab. Columns and filters come from the section's
 * data-config; the server filters, sorts and pages. The freshness strip is
 * refreshed every 5 s while a fetch runs and every 60 s otherwise, paused
 * while the browser tab is hidden.
 */
(function ($) {
    'use strict';

    const SEARCH_DEBOUNCE_MS = 400;
    const POLL_FETCHING_MS = 5000;
    const POLL_IDLE_MS = 60000;

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // The currency goes in a tooltip, not the cell, so narrow columns fit.
    const money = (value, currency) => (value === null || value === undefined || value === '') ? '—' : `<span title="${esc(currency || '')}">${Number(value).toFixed(2)}</span>`;
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

    function FeedCatalog($root) {
        this.$root = $root;
        this.cfg = $root.data('config');
        this.storageKey = `mmiFeedCatalog_${this.cfg.source}`;
        this.state = { q: '', f: {}, promo: [], on_site: [], sort: 'product', dir: 'asc', page: 1, per_page: 50 };
        this.facetsLoaded = false;
        this.openSku = null;
        this.request = null;
        this.pollTimer = null;
        this.wasFetching = false;
        this.lastStatus = null;
        this.init();
    }

    FeedCatalog.prototype = {
        $: function (selector) {
            return this.$root.find(selector);
        },

        ajax: function (action, data) {
            return $.post(this.cfg.ajaxUrl, Object.assign({ action: `mmi_feed_catalog_${action}`, nonce: this.cfg.nonce, source: this.cfg.source }, data || {}));
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
            this.state.promo = asList(this.state.promo).filter((v) => ['active', 'upcoming', 'none'].includes(v));
            this.state.on_site = asList(this.state.on_site).filter((v) => ['publish', 'other', 'none'].includes(v));

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
                Object.assign(this.state, { q: '', f: {}, promo: [], on_site: [], page: 1 });
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
                if ($(e.target).is('a, a *, button, button *') || window.getSelection().toString().length > 0) {
                    return;
                }
                this.toggle(String($(e.currentTarget).data('sku')));
            });
            this.$('.mmi-fc-fetch').on('click', () => this.fetchNow());
            document.addEventListener('visibilitychange', () => this.schedulePoll());

            this.syncControls();
            this.load();
        },

        // ── Multi-select filters (shared .mmi-multiselect markup) ──────────
        filterValues: function (key) {
            return (key === 'promo' || key === 'on_site' ? this.state[key] : this.state.f[key]) || [];
        },

        setFilterValues: function (key, values) {
            if (key === 'promo' || key === 'on_site') {
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
                const { q, f, promo, on_site, sort, dir, per_page } = this.state;
                localStorage.setItem(this.storageKey, JSON.stringify({ q, f, promo, on_site, sort, dir, per_page }));
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
                this.$('.mmi-fc-count').text(`${d.total.toLocaleString()} of ${d.all.toLocaleString()} products`);
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
            this.$('.mmi-fc-tbody').html(`<tr><td colspan="${this.cfg.columns.length}" class="mmi-fc-empty">${html}</td></tr>`);
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
                        ? `${money(r.promo.price, r.currency)} <span class="mmi-badge ${r.promo.state === 'active' ? 'success' : 'info'}" title="${esc(r.promo.name)}: ${esc(r.promo.start)} to ${esc(r.promo.end || 'open')}">${r.promo.state === 'active' ? 'now' : 'from ' + esc(r.promo.start)}</span>`
                        : '—';
                case 'site':
                    return r.site_id
                        ? `<span class="mmi-badge ${r.site_status === 'publish' ? 'success' : 'warning'}">${r.site_status === 'publish' ? 'Published' : esc(r.site_status)}</span>`
                        : '<span class="mmi-fc-muted">Not listed</span>';
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
            this.$('.mmi-fc-tbody').html(rows.map((r) => {
                const open = this.openSku === String(r.sku);
                const tds = cols.map((c) => `<td class="mmi-fc-td-${c.type}">${this.cell(c, r)}</td>`).join('');
                return `<tr class="mmi-fc-row${open ? ' is-open' : ''}" data-sku="${esc(r.sku)}">${tds}</tr>${open ? this.detailRowHtml(r.sku) : ''}`;
            }).join(''));
            if (this.openSku && this.$('.mmi-fc-detail-row').length) {
                this.loadDetail(this.openSku);
            }
        },

        detailRowHtml: function (sku) {
            return `<tr class="mmi-fc-detail-row" data-sku="${esc(sku)}"><td colspan="${this.cfg.columns.length}"><div class="mmi-fc-detail"><p class="mmi-fc-empty"><span class="mmi-loading"></span> Loading…</p></div></td></tr>`;
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
            const promos = d.promos.length
                ? `<div class="mmi-fc-block"><h4>Promotions</h4><table class="mmi-fc-mini"><thead><tr><th>Promotion</th><th>Promo cost</th><th>Regular</th><th>From</th><th>To</th><th>State</th></tr></thead><tbody>${d.promos.map((p) => `<tr><td>${esc(p.name || '—')}${p.code ? ` <span class="mmi-fc-sub">${esc(p.code)}</span>` : ''}</td><td>${money(p.price)}</td><td>${money(p.regular)}</td><td>${esc(p.start || '')}</td><td>${esc(p.end || '')}</td><td>${esc(p.state || '')}</td></tr>`).join('')}</tbody></table></div>`
                : '';
            const fields = Object.entries(d.fields).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`).join('');
            const main = text || lists ? `${text}` : '<p class="mmi-fc-muted">No description in this feed.</p>';
            return `<div class="mmi-fc-detail-head"><strong>${esc(d.product)}</strong><span class="mmi-fc-sub">${esc(d.sku)}</span><div class="mmi-fc-detail-actions">${site}</div></div>
                ${gallery}${imagesNote}
                <div class="mmi-fc-columns"><div>${main}</div><div>${lists}${promos}</div></div>
                <details class="mmi-fc-fields"><summary>Every feed field</summary><table class="mmi-fc-mini"><tbody>${fields}</tbody></table></details>`;
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
                state = s.next_at ? `Next automatic fetch ${esc(s.next_at)}.` : '';
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

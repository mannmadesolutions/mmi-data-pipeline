<?php

use MannMade\Integrations\Helpers\MMI_Updater_Trait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown for any Plugivery API envelope with a non-zero `error` code. The
 * exception code IS Plugivery's numeric error code (451, 472, 473, …), so
 * callers branch on getCode(), never on error_txt wording.
 */
class MMI_Plugivery_API_Exception extends RuntimeException {}

/**
 * MMI_Pipeline_Plugivery_Updater
 *
 * Plugivery Catalog API (v1.31) → uploads/mmi-json/. See
 * mmi-admin/docs/api-reference/plugivery-api.md for the vendor contract.
 *
 * Two independently-paced halves, because the account is capped at
 * 300 reads/day and 4,000 reads/month (exceeding either risks revocation):
 *
 * 1. run() — the scheduled source fetch. ~3-5 reads: act=list since=all
 *    (the whole catalog's ids + pricing in one call), act=list_promotions
 *    for current (state 1) and upcoming (state 0) promotions,
 *    and list_brands/list_cats only when their file is over a week old.
 *    Writes plugivery-products.json (the import feed) and, if any product's
 *    detail is missing or older than its list `date`, queues one detail
 *    batch.
 * 2. run_detail_batch() — Action Scheduler chain doing act=get per stale
 *    product, DETAIL_BATCH_SIZE / DETAIL_TIME_BUDGET per batch, re-queuing
 *    itself until nothing is stale or the day's budget is spent. The detail
 *    cache (plugivery-product-details.json) keeps images; the import feed
 *    deliberately does not — Plugivery images must never be hotlinked, only
 *    downloaded and rehosted (see "Usage policy" in the API doc).
 *
 * Every counted read goes through request(), which checks and records the
 * local quota ledger (QUOTA_KEY) before sending — the API has no "remaining
 * reads" endpoint, and act=info reports only the caps, not usage.
 *
 * WP-CLI:
 *   wp mmi fetch-plugivery [--details=<n>]   fetch, then up to n detail reads inline
 *   wp mmi plugivery-status                   quota ledger + feed/detail coverage (0 reads)
 */
class MMI_Pipeline_Plugivery_Updater {
    use MMI_Updater_Trait;

    const LOG_CATEGORY = 'plugivery'; // shown on mmi-plugivery-integration's Logs tab

    const THROTTLE_KEY = 'plugivery';

    const DAILY_LIMIT     = 300;
    const MONTHLY_LIMIT   = 4000;
    // Headroom left untouched for manual/diagnostic calls and for any read
    // Plugivery counts that our ledger missed (e.g. a timed-out request it
    // actually served).
    const DAILY_RESERVE   = 20;
    const MONTHLY_RESERVE = 200;

    // Plugivery bills in GST/QST (Quebec), so its day/month boundaries are
    // assumed to be Eastern time. A 472/473 response overrides the ledger
    // regardless, so a wrong guess costs one refused read, not a revocation.
    const QUOTA_TIMEZONE = 'America/Toronto';

    const QUOTA_KEY        = 'mmi_pipeline_plugivery_quota';
    const DETAIL_STATE_KEY = 'mmi_pipeline_plugivery_detail_state';
    const DETAIL_LOCK_KEY  = 'mmi_pipeline_plugivery_detail_lock';

    const DETAIL_HOOK               = 'mmi_pipeline_plugivery_detail_batch';
    const AS_GROUP                  = 'mmi-pipeline-plugivery';
    // Must fit well inside Action Scheduler's ~30 s async-runner window: a
    // 40 s batch plus other queued actions made one runner loopback outlast
    // the proxy timeout (504, 2026-09-25 17:22). ~1 s per act=get.
    const DETAIL_BATCH_SIZE         = 15;
    const DETAIL_TIME_BUDGET        = 20;   // seconds per AS batch
    const DETAIL_LOCK_TTL           = 300;  // AS per-batch lock max (Operational Continuity Rule 4)
    const DETAIL_MAX_FAILED_BATCHES = 3;    // consecutive zero-success batches before stopping (Rule 10)

    const FEED_FILE       = 'plugivery-products.json';
    const LIST_FILE       = 'plugivery-list.json';
    const DETAIL_FILE     = 'plugivery-product-details.json';
    const PROMOTIONS_FILE = 'plugivery-promotions.json';
    const PROMOTIONS_FUTURE_FILE = 'plugivery-promotions-future.json';
    const BRANDS_FILE     = 'plugivery-brands.json';
    const CATEGORIES_FILE = 'plugivery-categories.json';
    const REFERENCE_MAX_AGE = WEEK_IN_SECONDS;

    const MAX_RETRIES = 2;

    // Codes that mean every further call will fail the same way — stop the
    // whole run rather than burning reads on the remaining products.
    const FATAL_CODES = [401, 403, 451, 453, 461, 472, 473, 474];

    // Detail fields merged into the import feed. img/images are excluded on
    // purpose (no-hotlink rule) — Phase B's enrichment rehosts them from the
    // detail cache.
    const FEED_DETAIL_FIELDS = ['name', 'desc', 'url', 'brand_id', 'brand_name', 'cat_id', 'cat_name', 'compats', 'videos'];

    /** @var string */
    protected $token;
    /** @var string Catalog endpoint, always ".../catalog/" */
    protected $endpoint;
    /** @var string */
    protected $jsonDir;

    /**
     * @param bool $full Accepted for MMI_Pipeline_Supplier_Fetch_Runner's
     *                   signature; every run already lists since=all (one read).
     */
    public function __construct(bool $full = false)
    {
        $this->bootstrap_wp();
        // mmi-plugivery-integration owns Plugivery credentials when active (same
        // facade shape as the XChange/SkuPort ones); the vault read is the
        // standalone fallback.
        $facade = class_exists('MMI_Plugivery_API') ? MMI_Plugivery_API::get_credentials() : null;
        $creds  = ($facade && $facade['catalog_token'] !== '' && $facade['catalog_url'] !== '')
            ? ['plugivery-api-token' => $facade['catalog_token'], 'plugivery-api-url' => $facade['catalog_url']]
            : $this->load_credentials('Credentials & API Keys', ['plugivery-api-token', 'plugivery-api-url']);
        $this->token    = (string) $creds['plugivery-api-token'];
        $this->endpoint = self::normalize_endpoint((string) $creds['plugivery-api-url']);
        $this->jsonDir  = $this->init_json_dir();
    }

    public static function register_hooks(): void
    {
        add_action(self::DETAIL_HOOK, [__CLASS__, 'handle_detail_batch']);
    }

    /**
     * The vault value has been saved as ".../catalog/?act=info" — strip any
     * query string and make sure the path ends in /catalog/.
     */
    public static function normalize_endpoint(string $url): string
    {
        $base = rtrim((string) preg_replace('/[?#].*$/', '', trim($url)), '/');
        if (!preg_match('~/catalog$~i', $base)) {
            $base .= '/catalog';
        }
        return $base . '/';
    }

    /**
     * Zero-cost reachability check: act=info with the real token is never
     * counted against the quota, yet still enforces the IP allowlist and
     * token (451/453/461), so it answers "will a real fetch work?" for free.
     *
     * @return array{ok:bool, code:int, message:string}
     */
    public function check_connection(): array
    {
        try {
            $this->request('info');
            return ['ok' => true, 'code' => 0, 'message' => 'Plugivery Catalog API reachable — token valid and this server\'s IP is allowlisted.'];
        } catch (MMI_Plugivery_API_Exception $e) {
            return ['ok' => false, 'code' => (int) $e->getCode(), 'message' => $e->getMessage()];
        }
    }

    /* ── Scheduled fetch ──────────────────────────────────────────────── */

    public function run(): void
    {
        $this->log('Plugivery fetch start');

        $listResponse = $this->request('list', ['since' => 'all']);
        $listRows     = $this->normalize_list_rows((array) ($listResponse['data'] ?? []));
        if (!$listRows) {
            throw new RuntimeException('Plugivery act=list since=all returned no products — refusing to overwrite the feed with an empty catalog.');
        }

        $this->save_json(['products' => $listRows], self::LIST_FILE, $this->jsonDir);
        $details = $this->read_details();
        $this->write_feed($listRows, $details);

        $promotions = array_values((array) ($this->request('list_promotions', ['state' => 1])['data'] ?? []));
        $this->save_json($promotions, self::PROMOTIONS_FILE, $this->jsonDir);

        // Announced-but-not-started promotions (state 0), so a price change can
        // be planned before it lands. One read a day.
        $future = array_values((array) ($this->request('list_promotions', ['state' => 0])['data'] ?? []));
        $this->save_json($future, self::PROMOTIONS_FUTURE_FILE, $this->jsonDir);

        foreach (['list_brands' => self::BRANDS_FILE, 'list_cats' => self::CATEGORIES_FILE] as $act => $file) {
            $mtime = @filemtime($this->jsonDir . $file);
            if ($mtime && (time() - $mtime) < self::REFERENCE_MAX_AGE) {
                continue;
            }
            $response = $this->request($act);
            $this->save_json(array_values((array) ($response['data'] ?? [])), $file, $this->jsonDir);
        }

        $stale = count($this->stale_ids($listRows, $details));
        $this->log(sprintf(
            'Plugivery fetch done: %d products listed, %d running promotions, %d upcoming promotions, %d need detail',
            count($listRows), count($promotions), count($future), $stale
        ));
        if ($stale > 0) {
            self::queue_detail_batch();
        }

        echo "SUCCESS: Plugivery data updated\n";
    }

    /* ── Detail enrichment (Action Scheduler chain) ───────────────────── */

    /**
     * @param bool $fromWorker The running batch re-queuing its successor —
     *                         skips the dedupe check, which counts the
     *                         in-progress action itself as "scheduled".
     */
    public static function queue_detail_batch(int $delay = 0, bool $fromWorker = false): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        if (!$fromWorker && as_next_scheduled_action(self::DETAIL_HOOK, [], self::AS_GROUP)) {
            return;
        }
        as_schedule_single_action(time() + $delay, self::DETAIL_HOOK, [], self::AS_GROUP, false, MMI_PIPELINE_AS_PRIORITY_BATCH);
    }

    /** Action Scheduler callback. */
    public static function handle_detail_batch(): void
    {
        if (MMI_DB::get_job_state(self::DETAIL_LOCK_KEY)) {
            return;
        }
        MMI_DB::set_job_state(self::DETAIL_LOCK_KEY, current_time('mysql'), self::DETAIL_LOCK_TTL);

        $state = array_merge([
            'run_id'              => gmdate('Ymd-Hi'),
            'started_at'          => current_time('mysql'),
            'total_products'      => 0,
            'products_synced'     => 0,
            'products_failed'     => 0,
            'failed_batches'      => 0,
            'last_error'          => null,
        ], (array) MMI_Settings::get(self::DETAIL_STATE_KEY, []));
        if (in_array($state['status'] ?? '', ['complete', 'failed', 'paused_quota'], true)) {
            $state = array_merge($state, [
                'run_id' => gmdate('Ymd-Hi'), 'started_at' => current_time('mysql'),
                'products_synced' => 0, 'products_failed' => 0, 'failed_batches' => 0, 'last_error' => null,
            ]);
        }
        $state['status']       = 'running';
        $state['completed_at'] = null;

        try {
            $updater = new self();
            $result  = $updater->fetch_details(self::DETAIL_BATCH_SIZE, self::DETAIL_TIME_BUDGET);

            $state['total_products']   = $result['stale_before'];
            $state['products_synced'] += $result['fetched'];
            $state['products_failed'] += $result['failed'];
            $state['failed_batches']   = ($result['fetched'] === 0 && $result['failed'] > 0) ? $state['failed_batches'] + 1 : 0;
            if ($result['error']) {
                $state['last_error'] = $result['error'];
            }

            if ($result['stopped_for_quota']) {
                $state['status'] = 'paused_quota';
            } elseif ($state['failed_batches'] >= self::DETAIL_MAX_FAILED_BATCHES) {
                $state['status'] = 'failed';
                MMI_Logger::error('Plugivery detail enrichment stopped after ' . self::DETAIL_MAX_FAILED_BATCHES . ' consecutive failed batches', ['last_error' => $state['last_error']], self::LOG_CATEGORY, __CLASS__);
            } elseif ($result['stale_after'] > 0) {
                MMI_Settings::set(self::DETAIL_STATE_KEY, $state);
                MMI_DB::delete_job_state(self::DETAIL_LOCK_KEY);
                self::queue_detail_batch(5, true);
                return;
            } else {
                $state['status'] = 'complete';
            }
        } catch (\Throwable $e) {
            $state['status']     = 'failed';
            $state['last_error'] = $e->getMessage();
            MMI_Logger::error('Plugivery detail batch failed: ' . $e->getMessage(), [], self::LOG_CATEGORY, __CLASS__);
        }

        $state['completed_at'] = current_time('mysql');
        MMI_Settings::set(self::DETAIL_STATE_KEY, $state);
        MMI_DB::delete_job_state(self::DETAIL_LOCK_KEY);
    }

    /**
     * Fetch act=get for up to $limit stale products, within $timeBudget
     * seconds and the remaining quota. Persists the detail cache and the
     * rebuilt feed before returning, so reads already spent are never lost.
     *
     * @return array{stale_before:int, stale_after:int, fetched:int, failed:int, stopped_for_quota:bool, error:?string}
     */
    public function fetch_details(int $limit, int $timeBudget = 0): array
    {
        $listRows = $this->read_list();
        $details  = $this->read_details();
        $stale    = $this->stale_ids($listRows, $details);
        $result   = ['stale_before' => count($stale), 'stale_after' => count($stale), 'fetched' => 0, 'failed' => 0, 'stopped_for_quota' => false, 'error' => null];
        if (!$stale) {
            return $result;
        }

        $started = microtime(true);
        foreach (array_slice($stale, 0, $limit) as $id) {
            if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) {
                break;
            }
            if (self::reads_available() < 1) {
                $result['stopped_for_quota'] = true;
                break;
            }
            try {
                $response = $this->request('get', ['id' => $id]);
                $record   = $response['data'] ?? null;
                if (is_array($record) && isset($record['id'])) {
                    $record['detail_fetched_at'] = gmdate('c');
                    $details[(string) $id] = $record;
                    $result['fetched']++;
                } else {
                    // 462 No Result: listed but no detail — remember it so it isn't re-read daily.
                    $details[(string) $id] = ['id' => (int) $id, 'date' => $listRows[(string) $id]['date'] ?? null, 'detail_missing' => true, 'detail_fetched_at' => gmdate('c')];
                    $result['failed']++;
                }
            } catch (MMI_Plugivery_API_Exception $e) {
                $result['failed']++;
                $result['error'] = $e->getMessage();
                if (in_array($e->getCode(), self::FATAL_CODES, true)) {
                    $result['stopped_for_quota'] = in_array($e->getCode(), [472, 473, 474], true);
                    break;
                }
            }
        }

        $this->save_json(['products' => $details], self::DETAIL_FILE, $this->jsonDir);
        $this->write_feed($listRows, $details);
        $result['stale_after'] = count($this->stale_ids($listRows, $details));
        $this->log(sprintf('Plugivery details: fetched %d, failed %d, %d still stale', $result['fetched'], $result['failed'], $result['stale_after']));
        return $result;
    }

    /* ── API client ───────────────────────────────────────────────────── */

    /**
     * One Catalog API call. Counted actions check and record the local quota
     * ledger before every attempt (retries included — Plugivery counts each).
     *
     * @throws MMI_Plugivery_API_Exception
     */
    protected function request(string $act, array $params = []): array
    {
        $counted = !in_array($act, ['info', 'doc'], true);
        $url     = $this->endpoint . '?' . http_build_query(array_merge(['token' => $this->token, 'act' => $act], $params), '', '&', PHP_QUERY_RFC3986);
        $lastError = '';

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($counted) {
                if (self::reads_available() < 1) {
                    throw new MMI_Plugivery_API_Exception("Plugivery read budget exhausted locally (act={$act}); not sending.", 472);
                }
                self::record_read();
            }
            \MMI_API_Throttler::throttle(self::THROTTLE_KEY);

            $response = wp_remote_get($url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);
            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                \MMI_API_Throttler::penalize(self::THROTTLE_KEY);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status === 429 || $status >= 500) {
                $lastError = "HTTP {$status}";
                \MMI_API_Throttler::penalize(self::THROTTLE_KEY, (int) wp_remote_retrieve_header($response, 'retry-after'));
                continue;
            }

            $json = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($json)) {
                throw new MMI_Plugivery_API_Exception("Plugivery act={$act}: invalid JSON (HTTP {$status})", 0);
            }

            $code = (int) ($json['error'] ?? 0);
            if ($code === 0) {
                self::update_quota(['last_success_at' => current_time('mysql'), 'last_error' => null]);
                return $json;
            }
            if ($code === 462) { // No Result
                return ['data' => null] + $json;
            }
            throw $this->api_error($code, (string) ($json['error_txt'] ?? ''), $act);
        }

        throw new MMI_Plugivery_API_Exception("Plugivery act={$act} failed after " . (self::MAX_RETRIES + 1) . " attempts: {$lastError}", 0);
    }

    protected function api_error(int $code, string $text, string $act): MMI_Plugivery_API_Exception
    {
        $message = "Plugivery act={$act} error {$code}: {$text}";
        if ($code === 451) {
            $message .= ' — this server\'s IP must be added to the Plugivery API allowlist (dealer portal / Plugivery support); the token is not the problem.';
        }

        $ledger = ['last_error' => ['code' => $code, 'text' => $text, 'act' => $act, 'at' => current_time('mysql')]];
        if ($code === 472) {
            $ledger['day_reads'] = self::DAILY_LIMIT;
        } elseif ($code === 473) {
            $ledger['month_reads'] = self::MONTHLY_LIMIT;
        } elseif ($code === 474) {
            $ledger['total_blocked'] = true;
        }
        self::update_quota($ledger);

        if (in_array($code, [472, 473, 474], true)) {
            MMI_Logger::warn($message, [], 'throttler', __CLASS__);
        } else {
            MMI_Logger::error($message, [], self::LOG_CATEGORY, __CLASS__);
        }
        return new MMI_Plugivery_API_Exception($message, $code);
    }

    /* ── Quota ledger ─────────────────────────────────────────────────── */

    /** The ledger rolled over to the current Plugivery day/month. */
    public static function quota(): array
    {
        $now   = new DateTimeImmutable('now', new DateTimeZone(self::QUOTA_TIMEZONE));
        $day   = $now->format('Y-m-d');
        $month = $now->format('Y-m');
        $q = array_merge([
            'day' => $day, 'day_reads' => 0, 'month' => $month, 'month_reads' => 0,
            'total_blocked' => false, 'last_error' => null, 'last_success_at' => null,
        ], (array) MMI_Settings::get(self::QUOTA_KEY, []));

        if ($q['day'] !== $day) {
            $q['day'] = $day;
            $q['day_reads'] = 0;
        }
        if ($q['month'] !== $month) {
            $q['month'] = $month;
            $q['month_reads'] = 0;
        }
        return $q;
    }

    public static function reads_available(): int
    {
        $q = self::quota();
        if ($q['total_blocked']) {
            return 0;
        }
        return max(0, min(
            self::DAILY_LIMIT - self::DAILY_RESERVE - (int) $q['day_reads'],
            self::MONTHLY_LIMIT - self::MONTHLY_RESERVE - (int) $q['month_reads']
        ));
    }

    protected static function record_read(): void
    {
        $q = self::quota();
        $q['day_reads']++;
        $q['month_reads']++;
        MMI_Settings::set(self::QUOTA_KEY, $q);
    }

    protected static function update_quota(array $changes): void
    {
        MMI_Settings::set(self::QUOTA_KEY, array_merge(self::quota(), $changes));
    }

    /* ── Files ────────────────────────────────────────────────────────── */

    /** @return array<string,array> id => normalized list row */
    protected function normalize_list_rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $clean = ['id' => (int) $row['id'], 'date' => isset($row['date']) ? (int) $row['date'] : null];
            foreach (['msrp', 'map', 'cost', 'reg_map', 'reg_cost'] as $price) {
                $clean[$price] = isset($row[$price]) ? (float) $row[$price] : null;
            }
            $out[(string) $clean['id']] = $clean;
        }
        return $out;
    }

    protected function read_list(): array
    {
        return (array) ($this->loadJson($this->jsonDir . self::LIST_FILE)['products'] ?? []);
    }

    protected function read_details(): array
    {
        return (array) ($this->loadJson($this->jsonDir . self::DETAIL_FILE)['products'] ?? []);
    }

    /** Listed ids whose cached detail is missing or older than the list's own `date`. */
    protected function stale_ids(array $listRows, array $details): array
    {
        $stale = [];
        foreach ($listRows as $id => $row) {
            $cached = $details[(string) $id] ?? null;
            if ($cached === null || (int) ($cached['date'] ?? 0) < (int) ($row['date'] ?? 0)) {
                $stale[] = (int) $id;
            }
        }
        return $stale;
    }

    /** The import feed: every currently-listed product, list pricing + non-image detail fields. */
    protected function write_feed(array $listRows, array $details): void
    {
        $feed = [];
        foreach ($listRows as $id => $row) {
            $record = $row;
            $detail = $details[(string) $id] ?? [];
            foreach (self::FEED_DETAIL_FIELDS as $field) {
                if (array_key_exists($field, $detail)) {
                    $record[$field] = $detail[$field];
                }
            }
            $record['detail_fetched_at'] = $detail['detail_fetched_at'] ?? null;
            $feed[] = $record;
        }
        $this->save_json($feed, self::FEED_FILE, $this->jsonDir);
    }
}

// WP-CLI command integration
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    \WP_CLI::add_command('mmi fetch-plugivery', function ($args, $assoc) {
        try {
            $updater = new MMI_Pipeline_Plugivery_Updater();
            $updater->run();
            $n = isset($assoc['details']) ? max(0, (int) $assoc['details']) : 0;
            if ($n > 0) {
                $r = $updater->fetch_details($n);
                \WP_CLI::log(sprintf('Details: fetched %d, failed %d, %d still stale%s', $r['fetched'], $r['failed'], $r['stale_after'], $r['error'] ? ' — ' . $r['error'] : ''));
            }
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }, [
        'shortdesc' => 'Fetch the Plugivery catalog (list, promotions, weekly brands/categories)',
        'synopsis'  => [
            ['type' => 'assoc', 'name' => 'details', 'optional' => true, 'description' => 'Also fetch up to this many stale product details inline (1 read each)'],
        ],
    ]);

    \WP_CLI::add_command('mmi plugivery-status', function () {
        $q = MMI_Pipeline_Plugivery_Updater::quota();
        \WP_CLI::log(sprintf('Quota (%s): day %s %d/%d, month %s %d/%d, available now %d%s',
            MMI_Pipeline_Plugivery_Updater::QUOTA_TIMEZONE,
            $q['day'], $q['day_reads'], MMI_Pipeline_Plugivery_Updater::DAILY_LIMIT,
            $q['month'], $q['month_reads'], MMI_Pipeline_Plugivery_Updater::MONTHLY_LIMIT,
            MMI_Pipeline_Plugivery_Updater::reads_available(),
            $q['total_blocked'] ? ' (TOTAL LIMIT HIT — blocked)' : ''));
        if (!empty($q['last_error'])) {
            \WP_CLI::log('Last error: ' . wp_json_encode($q['last_error']));
        }
        \WP_CLI::log('Detail state: ' . wp_json_encode(MMI_Settings::get(MMI_Pipeline_Plugivery_Updater::DETAIL_STATE_KEY, null)));
        $dir = mmi_shared_lib_json_dir();
        foreach ([MMI_Pipeline_Plugivery_Updater::FEED_FILE, MMI_Pipeline_Plugivery_Updater::DETAIL_FILE] as $f) {
            \WP_CLI::log($f . ': ' . (file_exists($dir . $f) ? gmdate('c', filemtime($dir . $f)) : 'missing'));
        }
    }, [
        'shortdesc' => 'Show the Plugivery read-quota ledger and feed/detail state (makes no API calls)',
    ]);
}

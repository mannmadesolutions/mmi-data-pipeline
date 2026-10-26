<?php
/**
 * Site Export
 *
 * Packages a whole WordPress install as one ZIP its owner can take to any
 * host: wp-content, a full database dump, a WordPress content export (WXR),
 * restore steps and SHA-256 checksums. The default target is this site; the
 * `mmi_data_pipeline_site_export_targets` filter adds others on the same
 * server (a host exporting the sites it looks after).
 *
 * A package can take minutes, so "Export site" only queues a job and starts a
 * detached WP-CLI worker (`wp mmi pipeline-site-export <job>`); the admin page
 * polls its status. Packages live in the suite's private directory and are
 * handed out through signed, expiring links (admin-post.php, no login), so a
 * customer who has left can still download theirs. Each link's expiry is also
 * the package's: a daily cleanup deletes packages whose last link has expired,
 * or that never got one within RETENTION_DAYS.
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Site_Export {

    const JOBS_KEY          = 'mmi_pipeline_site_exports';
    const JOB_PREFIX        = 'mmi_pipeline_site_export_';
    const MAX_JOBS          = 50;
    const DOWNLOAD_ACTION   = 'mmi_pipeline_site_export_download';
    const CLEANUP_HOOK      = 'mmi_pipeline_site_export_cleanup';
    const SPAWN_HOOK        = 'mmi_pipeline_site_export_spawn';
    const MAX_DEFERS        = 5;    // Web-run cron hand-offs before a job gives up.
    const DEFAULT_LINK_DAYS = 30;
    const MAX_LINK_DAYS     = 90;
    const RETENTION_DAYS    = 7;    // A package nobody made a link for.
    const WORKER_TIMEOUT    = 3600; // Seconds; the worker is killed after this.
    const STALE_AFTER       = 3900; // A running job older than this died with its worker.
    const PROGRESS_EVERY    = 500;  // Files packed between status saves.

    /** wp-content folders that are rebuilt by the site itself or are other backups. */
    const SKIP_DIRS = [ 'cache', 'upgrade', 'upgrade-temp-backup', 'backups', 'backup', 'updraft', 'ai1wm-backups', 'node_modules', '.git' ];

    public static function init(): void {
        add_action( 'wp_ajax_mmi_pipeline_site_export_start', [ self::class, 'ajax_start' ] );
        add_action( 'wp_ajax_mmi_pipeline_site_export_rows', [ self::class, 'ajax_rows' ] );
        add_action( 'wp_ajax_mmi_pipeline_site_export_link', [ self::class, 'ajax_link' ] );
        add_action( 'wp_ajax_mmi_pipeline_site_export_delete', [ self::class, 'ajax_delete' ] );
        add_action( 'admin_post_' . self::DOWNLOAD_ACTION, [ self::class, 'handle_download' ] );
        add_action( 'admin_post_nopriv_' . self::DOWNLOAD_ACTION, [ self::class, 'handle_download' ] );
        add_action( self::CLEANUP_HOOK, [ self::class, 'cleanup' ] );
        add_action( self::SPAWN_HOOK, [ self::class, 'start_worker' ] );
        add_action( 'init', [ self::class, 'schedule_cleanup' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'mmi pipeline-site-export', [ self::class, 'cli_run' ] );
        }
    }

    public static function schedule_cleanup(): void {
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    /* ── Targets ─────────────────────────────────────────────────────────── */

    /**
     * Sites that can be exported: key => [ label, path ]. Only entries whose
     * path holds a WordPress install (wp-content plus wp-config.php there or
     * one level up) survive.
     *
     * @return array<string, array{label: string, path: string}>
     */
    public static function targets(): array {
        $targets = [
            'this-site' => [
                'label' => sprintf( '%s (this site)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
                'path'  => ABSPATH,
            ],
        ];
        $targets = (array) apply_filters( 'mmi_data_pipeline_site_export_targets', $targets );

        $valid = [];
        foreach ( $targets as $key => $target ) {
            $key  = sanitize_key( (string) $key );
            $path = wp_normalize_path( (string) ( $target['path'] ?? '' ) );
            if ( $key === '' || ! path_is_absolute( $path ) || strpos( $path, '..' ) !== false ) {
                continue;
            }
            $valid[ $key ] = [
                'label' => (string) ( $target['label'] ?? $key ),
                'path'  => trailingslashit( $path ),
            ];
        }
        return $valid;
    }

    /**
     * Whether a path holds a WordPress install. Checked by the worker: web
     * requests are often confined to their own site's folder (open_basedir)
     * and can't see other installs, so the target list only checks the form.
     */
    private static function is_install( string $path ): bool {
        return is_dir( $path . 'wp-content' ) && ( is_file( $path . 'wp-config.php' ) || is_file( dirname( $path ) . '/wp-config.php' ) );
    }

    /* ── Jobs ────────────────────────────────────────────────────────────── */

    /*
     * Each job is its own setting (JOB_PREFIX . id) and JOBS_KEY lists the IDs,
     * newest first. The worker only ever writes its own job, so its progress
     * saves can't overwrite a link made for another package meanwhile (each
     * process caches settings it has read).
     */

    /** @return string[] */
    private static function job_ids(): array {
        $ids = MMI_Settings::get( self::JOBS_KEY, [] );
        return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : [];
    }

    /** @return array<string, array> newest first */
    public static function jobs(): array {
        $ids = self::job_ids();
        if ( $ids && method_exists( 'MMI_Settings', 'preload' ) ) {
            MMI_Settings::preload( array_map( fn( $id ) => self::JOB_PREFIX . $id, $ids ) );
        }
        $jobs = [];
        foreach ( $ids as $id ) {
            $job = self::job( $id );
            if ( $job ) {
                $jobs[ $id ] = $job;
            }
        }
        return $jobs;
    }

    private static function job( string $id ): ?array {
        $job = MMI_Settings::get( self::JOB_PREFIX . $id, null );
        return is_array( $job ) ? $job : null;
    }

    private static function save_job( array $job ): void {
        MMI_Settings::set( self::JOB_PREFIX . $job['id'], $job );
    }

    /** Adds a new job at the top of the list, dropping the oldest finished ones past MAX_JOBS. */
    private static function add_job( array $job ): void {
        self::save_job( $job );
        $ids = array_merge( [ $job['id'] ], array_diff( self::job_ids(), [ $job['id'] ] ) );
        foreach ( array_slice( $ids, self::MAX_JOBS ) as $old ) {
            $old_job = self::job( $old );
            if ( $old_job ) {
                @unlink( self::package_path( $old_job ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            MMI_Settings::delete( self::JOB_PREFIX . $old );
        }
        MMI_Settings::set( self::JOBS_KEY, array_slice( $ids, 0, self::MAX_JOBS ) );
    }

    private static function valid_id( string $id ): bool {
        return (bool) preg_match( '/^\d{8}-\d{6}(-[a-z0-9-]{1,40})?-[a-z0-9]{6}$/', $id );
    }

    private static function dir(): string {
        $dir = function_exists( 'mmi_shared_lib_private_subdir' ) ? mmi_shared_lib_private_subdir( 'site-exports' ) : '';
        if ( $dir === '' ) {
            $dir = trailingslashit( MMI_Export_File_Manager::get_exports_dir() ) . 'site-exports/';
            wp_mkdir_p( $dir );
        }
        return trailingslashit( $dir );
    }

    private static function package_path( array $job ): string {
        return self::dir() . $job['id'] . '.zip';
    }

    /* ── Admin actions ───────────────────────────────────────────────────── */

    private static function check_ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::audit( 'site_export.denied', [ 'outcome' => 'denied' ] );
            wp_send_json_error( [ 'message' => 'You are not allowed to export sites.' ], 403 );
        }
        check_ajax_referer( 'mmi_pipeline_site_export', 'nonce' );
    }

    public static function ajax_start(): void {
        self::check_ajax();
        $key     = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) );
        $targets = self::targets();
        if ( ! isset( $targets[ $key ] ) ) {
            wp_send_json_error( [ 'message' => 'Choose a site to export.' ] );
        }
        foreach ( self::jobs() as $job ) {
            if ( $job['target'] === $key && in_array( $job['status'], [ 'queued', 'running' ], true ) && ! self::is_stale( $job ) ) {
                wp_send_json_error( [ 'message' => 'That site is already being exported.' ] );
            }
        }

        $target = $targets[ $key ];
        $job    = [
            'id'           => gmdate( 'Ymd-His' ) . '-' . substr( sanitize_title( $target['label'] ), 0, 40 ) . '-' . strtolower( wp_generate_password( 6, false, false ) ),
            'target'       => $key,
            'label'        => $target['label'],
            'path'         => $target['path'],
            'status'       => 'queued',
            'step'         => 'Waiting for the worker',
            'created'      => time(),
            'started'      => 0,
            'finished'     => 0,
            'size'         => 0,
            'sha256'       => '',
            'error'        => '',
            'warnings'     => [],
            'secret'       => wp_generate_password( 40, false, false ),
            'link_expires' => 0,
            'downloads'    => 0,
            'user'         => get_current_user_id(),
        ];
        $job['id'] = preg_replace( '/-+/', '-', $job['id'] );
        self::add_job( $job );

        self::queue_worker( $job );
        self::audit( 'site_export.start', [ 'details' => [ 'job' => $job['id'], 'target' => $key ] ] );
        wp_send_json_success( [ 'html' => self::render_rows() ] );
    }

    public static function ajax_rows(): void {
        self::check_ajax();
        wp_send_json_success( [ 'html' => self::render_rows(), 'busy' => self::any_busy() ] );
    }

    public static function ajax_link(): void {
        self::check_ajax();
        $id   = sanitize_text_field( wp_unslash( $_POST['job'] ?? '' ) );
        $days = max( 1, min( self::MAX_LINK_DAYS, absint( $_POST['days'] ?? self::DEFAULT_LINK_DAYS ) ) );
        $job  = self::valid_id( $id ) ? self::job( $id ) : null;
        if ( ! $job || $job['status'] !== 'done' || ! is_file( self::package_path( $job ) ) ) {
            wp_send_json_error( [ 'message' => 'That package is not available.' ] );
        }
        $expires             = time() + $days * DAY_IN_SECONDS;
        $job['link_expires'] = $expires;
        self::save_job( $job );
        self::audit( 'site_export.link', [ 'details' => [ 'job' => $id, 'days' => $days ] ] );
        wp_send_json_success( [
            'url'     => self::link_url( $job, $expires ),
            'expires' => wp_date( 'M j, Y', $expires ),
            'html'    => self::render_rows(),
        ] );
    }

    public static function ajax_delete(): void {
        self::check_ajax();
        $id  = sanitize_text_field( wp_unslash( $_POST['job'] ?? '' ) );
        $job = self::valid_id( $id ) ? self::job( $id ) : null;
        if ( ! $job ) {
            wp_send_json_error( [ 'message' => 'That package is already gone.' ] );
        }
        if ( in_array( $job['status'], [ 'queued', 'running' ], true ) && ! self::is_stale( $job ) ) {
            wp_send_json_error( [ 'message' => 'Wait for the export to finish before deleting it.' ] );
        }
        self::delete_package( $job, 'deleted' );
        self::audit( 'site_export.delete', [ 'details' => [ 'job' => $id ] ] );
        wp_send_json_success( [ 'html' => self::render_rows() ] );
    }

    /* ── Worker ──────────────────────────────────────────────────────────── */

    /** WP-CLI executable, or '' when the server has none. */
    private static function wp_bin(): string {
        $bin = (string) apply_filters( 'mmi_data_pipeline_wp_cli_bin', '' );
        if ( $bin === '' && function_exists( 'exec' ) ) {
            $found = [];
            exec( 'command -v wp 2>/dev/null', $found ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixed command.
            $bin = trim( (string) ( $found[0] ?? '' ) );
        }
        if ( $bin === '' ) {
            foreach ( [ '/usr/local/bin/wp', '/usr/bin/wp' ] as $candidate ) {
                if ( is_executable( $candidate ) ) {
                    $bin = $candidate;
                    break;
                }
            }
        }
        return ( $bin !== '' && is_executable( $bin ) ) ? $bin : '';
    }

    /**
     * Starts the worker now from WP-CLI; from a web request, through a one-off
     * WP-Cron event instead. Web PHP is often barred from starting processes,
     * and where WP-Cron is run by the system cron under PHP CLI the event can.
     */
    private static function queue_worker( array $job ): void {
        if ( PHP_SAPI === 'cli' ) {
            self::start_worker( $job['id'] );
            return;
        }
        $job['step'] = 'Waiting for the background runner (usually under a minute)';
        self::save_job( $job );
        wp_schedule_single_event( time(), self::SPAWN_HOOK, [ $job['id'] ] );
    }

    /** WP-Cron (SPAWN_HOOK): starts a queued job's detached worker, or records why it can't. */
    public static function start_worker( string $id ): void {
        $job = self::valid_id( $id ) ? self::job( $id ) : null;
        if ( ! $job || $job['status'] !== 'queued' ) {
            return;
        }
        if ( PHP_SAPI !== 'cli' && ! function_exists( 'exec' ) && (int) ( $job['defers'] ?? 0 ) < self::MAX_DEFERS ) {
            // A web-run cron landed it; wait for a CLI run that may start processes.
            $job['defers'] = (int) ( $job['defers'] ?? 0 ) + 1;
            self::save_job( $job );
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SPAWN_HOOK, [ $id ] );
            return;
        }
        $started = self::spawn( $job );
        if ( $started !== true ) {
            $job['status']   = 'failed';
            $job['error']    = $started;
            $job['finished'] = time();
            self::save_job( $job );
        }
    }

    /** @return true|string true when the worker was started, else why not */
    private static function spawn( array $job ) {
        if ( ! function_exists( 'exec' ) ) {
            return 'This server does not allow PHP to start background processes (exec is disabled).';
        }
        $bin = self::wp_bin();
        if ( $bin === '' ) {
            return 'WP-CLI was not found on this server; site exports run through it.';
        }
        $detach = is_executable( '/usr/bin/setsid' ) ? '/usr/bin/setsid ' : '';
        $limit  = is_executable( '/usr/bin/timeout' ) ? '/usr/bin/timeout ' . self::WORKER_TIMEOUT . ' ' : '';
        $cmd    = 'cd ' . escapeshellarg( ABSPATH ) . ' && ' . $detach . $limit . escapeshellarg( $bin )
            . ' mmi pipeline-site-export ' . escapeshellarg( $job['id'] )
            . ' > ' . escapeshellarg( self::dir() . $job['id'] . '.log' ) . ' 2>&1 &';
        exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixed command, escaped args.
        return true;
    }

    /**
     * `wp mmi pipeline-site-export <job>` — builds the package for a queued job.
     *
     * @param array $args
     */
    public static function cli_run( $args ): void {
        $id  = (string) ( $args[0] ?? '' );
        $job = self::valid_id( $id ) ? self::job( $id ) : null;
        if ( ! $job || $job['status'] !== 'queued' ) {
            \WP_CLI::error( 'No queued site export with that ID.' );
        }
        $job['status']  = 'running';
        $job['started'] = time();
        self::save_job( $job );

        $work = self::dir() . $id . '.work/';
        try {
            wp_mkdir_p( $work );
            $job = self::build( $job, $work );
            $job['status']   = 'done';
            $job['step']     = '';
            $job['finished'] = time();
            self::save_job( $job );
            self::audit( 'site_export.finish', [ 'details' => [ 'job' => $id, 'bytes' => $job['size'] ] ] );
            \WP_CLI::success( "Packed {$job['label']} ({$job['size']} bytes)." );
        } catch ( \Throwable $e ) {
            $job['status']   = 'failed';
            $job['error']    = $e->getMessage();
            $job['finished'] = time();
            self::save_job( $job );
            @unlink( self::package_path( $job ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            self::audit( 'site_export.finish', [ 'outcome' => 'failure', 'details' => [ 'job' => $id, 'error' => $e->getMessage() ] ] );
            \WP_CLI::warning( $e->getMessage() );
        } finally {
            self::remove_dir( $work );
        }
    }

    private static function build( array $job, string $work ): array {
        $bin  = self::wp_bin();
        $path = $job['path'];
        if ( $bin === '' ) {
            throw new \RuntimeException( 'WP-CLI is no longer available on this server.' );
        }
        if ( ! self::is_install( $path ) ) {
            throw new \RuntimeException( 'No WordPress install was found at that site\'s folder.' );
        }
        // The content export loads the site's plugins so their post types
        // (WooCommerce products and the like) are exported; the rest don't need them.
        $wp = function ( string $command, bool $with_plugins = false ) use ( $bin, $path ): array {
            $out  = [];
            $code = 0;
            $skip = $with_plugins ? ' --skip-themes ' : ' --skip-plugins --skip-themes ';
            exec( escapeshellarg( $bin ) . ' --path=' . escapeshellarg( $path ) . $skip . $command . ' 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- escaped args.
            return [ $code, trim( implode( "\n", $out ) ) ];
        };

        $job = self::step( $job, 'Exporting the database' );
        [ $code, $out ] = $wp( 'db export ' . escapeshellarg( $work . 'database.sql' ) );
        if ( $code !== 0 || ! is_file( $work . 'database.sql' ) ) {
            throw new \RuntimeException( 'Database export failed: ' . substr( $out, 0, 300 ) );
        }
        [ , $prefix ]  = $wp( 'config get table_prefix' );
        [ , $site_url ] = $wp( 'option get siteurl' );
        [ , $wp_ver ]   = $wp( 'core version' );

        $job = self::step( $job, 'Exporting the content (WXR)' );
        [ $code, $out ] = $wp( 'export --dir=' . escapeshellarg( $work ) . ' --filename_format=content.xml', true );
        if ( $code !== 0 || ! is_file( $work . 'content.xml' ) ) {
            $job['warnings'][] = 'The WordPress content export (content.xml) could not be made; everything is still in database.sql.';
        }

        $job  = self::step( $job, 'Packing files' );
        $zip  = new \ZipArchive();
        $file = self::package_path( $job );
        if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            throw new \RuntimeException( 'Could not create the ZIP file.' );
        }
        $sums = [];
        foreach ( [ 'database.sql', 'content.xml' ] as $name ) {
            if ( is_file( $work . $name ) ) {
                $zip->addFile( $work . $name, $name );
                $sums[ $name ] = hash_file( 'sha256', $work . $name );
            }
        }

        $content = $path . 'wp-content';
        $skip    = realpath( self::dir() ) ?: '';
        $count   = 0;
        $iter    = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator( $content, \FilesystemIterator::SKIP_DOTS ),
                function ( \SplFileInfo $f ) use ( $content, $skip ) {
                    if ( $f->isLink() ) {
                        return false;
                    }
                    if ( $f->isDir() ) {
                        $real = $f->getRealPath();
                        if ( $skip !== '' && $real === $skip ) {
                            return false;
                        }
                        // Only wp-content's own top-level folders are matched against SKIP_DIRS.
                        return ! ( dirname( $f->getPathname() ) === $content && in_array( strtolower( $f->getFilename() ), self::SKIP_DIRS, true ) )
                            && ! in_array( $f->getFilename(), [ 'node_modules', '.git' ], true );
                    }
                    return ! preg_match( '/\.(log|tmp)$/i', $f->getFilename() );
                }
            )
        );
        foreach ( $iter as $f ) {
            if ( ! $f->isFile() || ! $f->isReadable() ) {
                continue;
            }
            $relative = 'wp-content/' . ltrim( substr( $f->getPathname(), strlen( $content ) ), '/' );
            $zip->addFile( $f->getPathname(), $relative );
            $sums[ $relative ] = hash_file( 'sha256', $f->getPathname() );
            if ( ++$count % self::PROGRESS_EVERY === 0 ) {
                $job = self::step( $job, sprintf( 'Packing files (%s so far)', number_format( $count ) ) );
            }
        }

        $manifest = '';
        foreach ( $sums as $name => $sum ) {
            $manifest .= $sum . '  ' . $name . "\n";
        }
        $zip->addFromString( 'SHA256SUMS.txt', $manifest );
        $zip->addFromString( 'README.txt', self::readme( $job, (string) $prefix, (string) $site_url, (string) $wp_ver, $count, $job['warnings'] ) );

        $job = self::step( $job, sprintf( 'Writing the ZIP (%s files)', number_format( $count ) ) );
        if ( ! $zip->close() || ! is_file( $file ) ) {
            throw new \RuntimeException( 'Could not finish the ZIP file (is the disk full?).' );
        }
        clearstatcache( true, $file );
        $job['size']   = (int) filesize( $file );
        $job['sha256'] = hash_file( 'sha256', $file );
        $job['files']  = $count;
        return $job;
    }

    private static function step( array $job, string $step ): array {
        $job['step'] = $step;
        self::save_job( $job );
        return $job;
    }

    private static function readme( array $job, string $prefix, string $site_url, string $wp_version, int $files, array $warnings ): string {
        $lines = [
            'Website export: ' . $job['label'],
            'Made: ' . gmdate( 'Y-m-d H:i' ) . ' UTC',
            'Site address: ' . ( $site_url ?: 'unknown' ),
            'WordPress version: ' . ( $wp_version ?: 'unknown' ),
            'Database table prefix: ' . ( $prefix ?: 'wp_' ),
            '',
            'WHAT IS INSIDE',
            '  database.sql     The complete database: pages, posts, settings, users, orders and everything else.',
            '  content.xml      The pages, posts and media list in WordPress\'s own export format (Tools > Import).',
            '  wp-content/      Your theme, plugins and uploaded files (' . number_format( $files ) . ' files).',
            '  SHA256SUMS.txt   A checksum for every file, to confirm nothing changed in transit.',
            '',
            'Not included: wp-config.php (it holds this server\'s passwords), caches and other backups.',
            '',
            'MOVING THE SITE TO A NEW HOST',
            '  1. Install the same WordPress version on the new host.',
            '  2. Replace its wp-content folder with the one in this ZIP.',
            '  3. Import database.sql into the new database (phpMyAdmin, or: wp db import database.sql).',
            '  4. In the new wp-config.php set $table_prefix = \'' . ( $prefix ?: 'wp_' ) . '\'; and generate new security keys',
            '     at https://api.wordpress.org/secret-key/1.1/salt/',
            '  5. If the address changes, replace it everywhere: wp search-replace \'' . ( $site_url ?: 'https://old-address' ) . '\' \'https://new-address\'',
            '  6. Point your domain\'s DNS at the new host.',
            '',
            'Premium plugins and themes need their own license keys on the new host; keys that came with',
            'your old plan are not transferred.',
        ];
        if ( $warnings ) {
            $lines[] = '';
            $lines[] = 'NOTES';
            foreach ( $warnings as $w ) {
                $lines[] = '  - ' . $w;
            }
        }
        return implode( "\n", $lines ) . "\n";
    }

    private static function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $iter as $f ) {
            $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    private static function is_stale( array $job ): bool {
        return in_array( $job['status'], [ 'queued', 'running' ], true ) && time() - (int) $job['created'] > self::STALE_AFTER;
    }

    private static function any_busy(): bool {
        foreach ( self::jobs() as $job ) {
            if ( in_array( $job['status'], [ 'queued', 'running' ], true ) && ! self::is_stale( $job ) ) {
                return true;
            }
        }
        return false;
    }

    /* ── Links and downloads ─────────────────────────────────────────────── */

    private static function signature( array $job, int $expires ): string {
        return hash_hmac( 'sha256', $job['id'] . '|' . $expires, $job['secret'] . wp_salt( 'auth' ) );
    }

    private static function link_url( array $job, int $expires ): string {
        return add_query_arg( [
            'action' => self::DOWNLOAD_ACTION,
            'job'    => $job['id'],
            'exp'    => $expires,
            'sig'    => self::signature( $job, $expires ),
        ], admin_url( 'admin-post.php' ) );
    }

    /** Public: anyone holding an unexpired signed link may download that one package. */
    public static function handle_download(): void {
        $id      = sanitize_text_field( wp_unslash( $_GET['job'] ?? '' ) );
        $expires = absint( $_GET['exp'] ?? 0 );
        $sig     = sanitize_text_field( wp_unslash( $_GET['sig'] ?? '' ) );
        $job     = self::valid_id( $id ) ? self::job( $id ) : null;

        $ok = $job && $job['status'] === 'done' && $expires >= time() && $expires <= (int) $job['link_expires']
            && hash_equals( self::signature( $job, $expires ), $sig );
        $file = $ok ? self::package_path( $job ) : '';
        $real = $file !== '' ? realpath( $file ) : false;
        if ( ! $ok || ! $real || strpos( $real, realpath( self::dir() ) ) !== 0 ) {
            self::audit( 'site_export.download', [ 'outcome' => 'denied', 'details' => [ 'job' => $id ] ] );
            wp_die( esc_html__( 'This download link has expired or is not valid. Ask us for a new one.', 'mmi-data-pipeline' ), '', [ 'response' => 403 ] );
        }

        $job['downloads'] = (int) $job['downloads'] + 1;
        self::save_job( $job );
        self::audit( 'site_export.download', [ 'details' => [ 'job' => $id ] ] );

        while ( ob_get_level() ) {
            ob_end_clean();
        }
        set_time_limit( 0 );
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( sanitize_title( $job['label'] ) . '-' . gmdate( 'Y-m-d', (int) $job['finished'] ) ) . '.zip"' );
        header( 'Content-Length: ' . filesize( $real ) );
        header( 'X-Robots-Tag: noindex' );
        // Chunked: hosts often disable fpassthru()/readfile(), and a package can be large.
        $fh = fopen( $real, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        while ( $fh && ! feof( $fh ) ) {
            echo fread( $fh, MB_IN_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions -- binary file.
            flush();
        }
        if ( $fh ) {
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        exit;
    }

    /* ── Cleanup ─────────────────────────────────────────────────────────── */

    private static function delete_package( array $job, string $status ): void {
        @unlink( self::package_path( $job ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @unlink( self::dir() . $job['id'] . '.log' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $job['status']       = $status;
        $job['link_expires'] = 0;
        $job['secret']       = '';
        self::save_job( $job );
    }

    /** Daily: deletes packages past their last link (or never linked and RETENTION_DAYS old); fails stale jobs. */
    public static function cleanup(): void {
        foreach ( self::jobs() as $job ) {
            if ( self::is_stale( $job ) ) {
                $job['status'] = 'failed';
                $job['error']  = 'The worker stopped before finishing.';
                self::save_job( $job );
                continue;
            }
            if ( $job['status'] !== 'done' ) {
                continue;
            }
            $ends = (int) $job['link_expires'] ?: (int) $job['finished'] + self::RETENTION_DAYS * DAY_IN_SECONDS;
            if ( $ends < time() ) {
                self::delete_package( $job, 'expired' );
                self::audit( 'site_export.expire', [ 'details' => [ 'job' => $job['id'] ] ] );
            }
        }
    }

    /* ── View ────────────────────────────────────────────────────────────── */

    public static function render_rows(): string {
        $jobs = self::jobs();
        if ( ! $jobs ) {
            return '<tr class="mmi-site-export-empty"><td colspan="7">No site exports yet.</td></tr>';
        }
        $badges = [
            'queued'  => [ 'info', 'Queued' ],
            'running' => [ 'info', 'Running' ],
            'done'    => [ 'success', 'Ready' ],
            'failed'  => [ 'error', 'Failed' ],
            'expired' => [ 'unknown', 'Expired' ],
            'deleted' => [ 'unknown', 'Deleted' ],
        ];
        ob_start();
        foreach ( $jobs as $job ) {
            $status = self::is_stale( $job ) ? 'failed' : $job['status'];
            [ $badge, $label ] = $badges[ $status ] ?? [ 'unknown', ucfirst( $status ) ];
            $note = $status === 'failed' ? ( $job['error'] ?: 'The worker stopped before finishing.' ) : ( in_array( $status, [ 'queued', 'running' ], true ) ? $job['step'] : implode( ' ', (array) $job['warnings'] ) );
            ?>
            <tr data-job="<?php echo esc_attr( $job['id'] ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
                <td class="mmi-site-export-id"><code><?php echo esc_html( $job['id'] ); ?></code></td>
                <td><?php echo esc_html( $job['label'] ); ?></td>
                <td data-sort="<?php echo (int) $job['created']; ?>"><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $job['created'] ) ); ?></td>
                <td>
                    <span class="mmi-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span>
                    <?php if ( $note !== '' ) : ?><span class="mmi-site-export-note"><?php echo esc_html( $note ); ?></span><?php endif; ?>
                </td>
                <td data-sort="<?php echo (int) $job['size']; ?>"><?php echo $job['size'] ? esc_html( size_format( (int) $job['size'], 1 ) ) : '—'; ?></td>
                <td data-sort="<?php echo (int) $job['link_expires']; ?>">
                    <?php echo $job['link_expires'] ? esc_html( wp_date( 'M j, Y', (int) $job['link_expires'] ) ) : '—'; ?>
                    <?php if ( (int) $job['downloads'] > 0 ) : ?><span class="mmi-site-export-note"><?php echo esc_html( sprintf( _n( '%d download', '%d downloads', (int) $job['downloads'], 'mmi-data-pipeline' ), (int) $job['downloads'] ) ); ?></span><?php endif; ?>
                </td>
                <td class="mmi-site-export-actions">
                    <?php if ( $status === 'done' ) : ?>
                        <button type="button" class="button button-small mmi-site-export-link"><?php echo $job['link_expires'] ? 'New link' : 'Copy link'; ?></button>
                    <?php endif; ?>
                    <?php if ( ! in_array( $status, [ 'queued', 'running', 'expired', 'deleted' ], true ) ) : ?>
                        <button type="button" class="button button-small mmi-site-export-delete">Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
        return (string) ob_get_clean();
    }

    private static function audit( string $action, array $args ): void {
        if ( class_exists( 'MMI_Audit_Log' ) ) {
            MMI_Audit_Log::record( 'mmi-data-pipeline', $action, $args );
        }
    }
}

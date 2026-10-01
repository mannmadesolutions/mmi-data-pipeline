<?php
/**
 * Pipeline Rate Limiter
 *
 * Self-contained AJAX rate limiter for mmi-data-pipeline.
 * Replaces the former dependency on MMI\VIP\Helpers\Ajax_Rate_Limiter.
 * Uses MMI_DB transient-style job-state storage (same backend as the rest
 * of the pipeline), so no additional infrastructure is required.
 *
 * @package MannMade\DataPipeline\Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Rate_Limiter {

    /**
     * Rate limit configuration.
     * [ action_key => [ max_requests, period_seconds ] ]
     */
    private static array $limits = [
        'mmi_pipeline_run_manual_import' => [ 5, 3600 ],  // 5 per hour
        'mmi_run_product_import'          => [ 5, 3600 ],  // legacy alias
        'default'                         => [ 60, 60  ],  // 60 per minute
    ];

    /**
     * Check whether the current request is within the rate limit for $action.
     *
     * @param  string $action  Action key (e.g. 'mmi_pipeline_run_manual_import').
     * @return bool            True if the request is allowed; false if rate-limited.
     */
    public static function check( string $action = '' ): bool {
        if ( empty( $action ) && isset( $_REQUEST['action'] ) ) {
            $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
        }

        [ $max_requests, $period ] = self::$limits[ $action ] ?? self::$limits['default'];

        $state_key = 'mmi_rate_limit_' . md5( self::get_key( $action ) );
        $count     = MMI_DB::get_job_state( $state_key );

        if ( $count === null ) {
            MMI_DB::set_job_state( $state_key, 1, $period );
            return true;
        }

        if ( (int) $count >= $max_requests ) {
            MMI_Logger::warn(
                sprintf( 'Rate limit exceeded for action "%s" (count=%d, max=%d).', $action, $count, $max_requests ),
                [],
                'general',
                'MMI_Pipeline_Rate_Limiter'
            );
            return false;
        }

        MMI_DB::set_job_state( $state_key, (int) $count + 1, $period );
        return true;
    }

    /**
     * Send a standardised rate-limit JSON error response and exit.
     *
     * @param  string $action  Action key, used only for the log message.
     * @return void
     */
    public static function send_rate_limit_error( string $action = '' ): void {
        wp_send_json_error(
            [
                'message' => 'Too many requests. Please wait before trying again.',
                'code'    => 'rate_limited',
            ],
            429
        );
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private static function get_key( string $action ): string {
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            return "user_{$user_id}_{$action}";
        }
        return 'ip_' . self::get_ip() . "_{$action}";
    }

    private static function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( explode( ',', wp_unslash( $_SERVER[ $key ] ) )[0] );
            }
        }
        return 'unknown';
    }
}

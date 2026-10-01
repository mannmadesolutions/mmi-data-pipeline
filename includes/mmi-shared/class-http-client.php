<?php
/**
 * HTTPClient (bundled/vendored copy) — ADR-0007, mmi-admin/docs/decisions/.
 *
 * Identical to the original mmi-hub/includes/HTTP/HTTPClient.php. Only ever
 * loaded once per request, by whichever plugin's bundled copy wins version
 * negotiation in bootstrap.php — see ADR-0006. Do not hand-edit this file in
 * a single plugin; edit the canonical source (mmi-admin/lib/mmi-shared/) and
 * re-sync (sync-to-plugins.sh) to every plugin that bundles it.
 */
namespace MannMade\Integrations\HTTP;

use Exception;

if ( ! \defined( 'ABSPATH' ) && ! \defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
    exit;
}

class HTTPClient
{
    /** Longest slice of a response body quoted in an exception message. */
    const ERROR_BODY_MAX = 500;

    /**
     * TLS certificate verification is never optional here: a caller's
     * 'sslverify' => false is overridden, not honoured.
     */
    private static function secure(array $args): array
    {
        $args['sslverify'] = true;
        return $args;
    }

    /**
     * Exception text for a non-2xx response. Exception messages end up in
     * logs and admin notices, so query-string values are masked (suppliers
     * put API keys there) and the body is truncated.
     */
    private static function status_error($code, string $url, string $body): string
    {
        $safe_url = preg_replace('/([?&][^=&#]+)=[^&#]*/', '$1=***', $url);
        if (strlen($body) > self::ERROR_BODY_MAX) {
            $body = substr($body, 0, self::ERROR_BODY_MAX) . '…';
        }
        if (class_exists('MMI_Logger') && method_exists('MMI_Logger', 'redact_string')) {
            $body = \MMI_Logger::redact_string($body);
        }
        return "Unexpected status {$code} for {$safe_url}: {$body}";
    }

    public function getJson(string $url, array $args = []): array
    {
        $args = array_merge([
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 60,
            'follow_redirects' => true,
        ], $args);
        $args = self::secure($args);

        // Add Xchange-specific headers for API requests
        if (strpos($url, 'xchangemarketb2b.com') !== false) {
            $args['headers']['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
            $args['headers']['Referer']    = 'https://xchangemarketb2b.com/';
            // Force HTTP/1.1 to avoid HTTP/2 protocol errors with Xchange API
            $args['httpversion'] = '1.1';

            // Throttle before every XChange API call
            if ( class_exists( 'MMI_API_Throttler' ) ) {
                \MMI_API_Throttler::throttle( 'xchange' );
            }
            
            // Use CURLOPT_USERPWD instead of Authorization header - Xchange server handles this better
            if (isset($args['headers']['Authorization']) && strpos($args['headers']['Authorization'], 'Basic ') === 0) {
                // Extract the base64 encoded credentials and decode them
                $base64Creds = substr($args['headers']['Authorization'], 6);
                $credentials = base64_decode($base64Creds);
                // Remove Authorization header and add curl option filter
                unset($args['headers']['Authorization']);
                
                // Hook into WordPress curl to set CURLOPT_USERPWD
                $curl_hook = function($handle) use ($credentials) {
                    curl_setopt($handle, CURLOPT_USERPWD, $credentials);
                };
                add_action('http_api_curl', $curl_hook, 10, 1);
                
                $response = wp_remote_get($url, $args);
                
                // Remove the hook immediately after use
                remove_action('http_api_curl', $curl_hook, 10);
            } else {
                $response = wp_remote_get($url, $args);
            }
        } else {
            $response = wp_remote_get($url, $args);
        }

        if ( is_wp_error($response) ) {
            throw new Exception( 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( self::status_error($code, $url, (string) $body) );
        }

        $data = json_decode($body, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'JSON decode error: ' . json_last_error_msg() );
        }

        // Check if response contains an error message (Xchange returns 200 with error JSON)
        if (isset($data['error'])) {
            $error_msg = $data['error'];
            // XChange rate-limit responses: penalize so next call waits longer
            if ( class_exists( 'MMI_API_Throttler' ) && strpos( $error_msg, 'too frequent' ) !== false ) {
                \MMI_API_Throttler::penalize( 'xchange' );
            }
            throw new Exception( "API Error: {$error_msg}" );
        }

        return $data;
    }

    public function postJson(string $url, array $body = [], array $args = []): array
    {
        $args = array_merge([
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body'            => wp_json_encode($body),
            'timeout'         => 60,
            'follow_redirects'=> true,
        ], $args);
        $args = self::secure($args);

        $response = wp_remote_post($url, $args);

        if ( is_wp_error($response) ) {
            throw new Exception( 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( self::status_error($code, $url, (string) $body) );
        }

        $data = json_decode($body, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'JSON decode error: ' . json_last_error_msg() );
        }

        return $data;
    }

    public function putJson(string $url, array $body = [], array $args = []): array
    {
        $args = array_merge([
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body'            => wp_json_encode($body),
            'timeout'         => 60,
            'method'          => 'PUT',
            'follow_redirects'=> true,
        ], $args);
        $args = self::secure($args);

        $response = wp_remote_request($url, $args);

        if ( is_wp_error($response) ) {
            throw new Exception( 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( self::status_error($code, $url, (string) $body) );
        }

        $data = json_decode($body, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'JSON decode error: ' . json_last_error_msg() );
        }

        return $data;
    }

    public function publishListing(string $listingId, array $args = []): array
    {
        $url = "https://api.reverb.com/api/listings/{$listingId}/publish";
        $args = array_merge([
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout'         => 60,
            'method'          => 'POST',
            'follow_redirects'=> true,
        ], $args);
        $args = self::secure($args);

        $response = wp_remote_post($url, $args);

        if ( is_wp_error($response) ) {
            throw new Exception( 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( self::status_error($code, $url, (string) $body) );
        }

        $data = json_decode($body, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'JSON decode error: ' . json_last_error_msg() );
        }

        return $data;
    }
}
<?php
/**
 * Private Uploads — keeps uploaded supplier feeds off the web.
 *
 * An 'upload' data source keeps its file as a WP attachment: the attachment
 * ID is the handle the source configuration, the upload fetcher, Import
 * Preview and the JS all pass around. media_handle_upload() puts that file
 * in uploads/YYYY/MM/, where anyone can download it by URL and the public
 * /wp/v2/media endpoint lists it — dealer price lists included (found live
 * 2026-10-01).
 *
 * The attachment stays as the handle; only the file moves. privatize()
 * moves it into mmi_shared_lib_private_subdir( 'data-pipeline-uploads' )
 * under a random name, points _wp_attached_file at that absolute path (so
 * get_attached_file() keeps working for every reader), and sets the post
 * to 'private' so REST and front-end queries never return it. Its URL is
 * blanked so no screen can leak the private folder's name, and the Media
 * Library leaves it out.
 *
 * migrate() privatizes every attachment an existing upload source points
 * at; it runs once per MIGRATION_VERSION on admin_init.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Private_Uploads {

    const META_FLAG         = '_mmi_pipeline_private_upload';
    const SUBDIR            = 'data-pipeline-uploads';
    const MIGRATION_KEY     = 'mmi_pipeline_private_uploads_migrated';
    const MIGRATION_VERSION = 1;

    public static function register_hooks(): void {
        add_filter( 'wp_get_attachment_url', [ self::class, 'filter_url' ], 99, 2 );
        add_filter( 'ajax_query_attachments_args', [ self::class, 'exclude_from_media_query' ] );
        add_action( 'pre_get_posts', [ self::class, 'exclude_from_media_list' ] );
        add_action( 'admin_init', [ self::class, 'maybe_migrate' ] );
    }

    public static function is_private( int $attachment_id ): bool {
        return (bool) get_post_meta( $attachment_id, self::META_FLAG, true );
    }

    /**
     * Move an attachment's file into the private directory.
     *
     * @return true|WP_Error
     */
    public static function privatize( int $attachment_id ) {
        if ( get_post_type( $attachment_id ) !== 'attachment' ) {
            return new WP_Error( 'mmi_private_upload_missing', 'Not an attachment.' );
        }
        if ( self::is_private( $attachment_id ) ) {
            return true;
        }

        $source = get_attached_file( $attachment_id );
        if ( ! $source || ! is_file( $source ) ) {
            return new WP_Error( 'mmi_private_upload_missing', 'Attachment file not found.' );
        }

        $dir = function_exists( 'mmi_shared_lib_private_subdir' ) ? mmi_shared_lib_private_subdir( self::SUBDIR ) : '';
        if ( $dir === '' || ! is_dir( $dir ) ) {
            return new WP_Error( 'mmi_private_upload_dir', 'Private directory unavailable.' );
        }

        $ext    = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
        $target = $dir . $attachment_id . '-' . strtolower( wp_generate_password( 16, false, false ) ) . ( $ext !== '' ? '.' . $ext : '' );

        // rename() is atomic on one filesystem; copy+unlink covers a
        // private dir on another mount.
        if ( ! @rename( $source, $target ) ) {
            if ( ! @copy( $source, $target ) ) {
                return new WP_Error( 'mmi_private_upload_move', 'Could not move the file.' );
            }
            @unlink( $source );
        }

        update_attached_file( $attachment_id, $target );
        update_post_meta( $attachment_id, self::META_FLAG, 1 );
        delete_post_meta( $attachment_id, '_wp_attachment_metadata' ); // no sizes for a feed
        wp_update_post( [ 'ID' => $attachment_id, 'post_status' => 'private' ] );

        mmi_data_pipeline_audit( 'file.privatize', [
            'object_type' => 'attachment',
            'object_id'   => $attachment_id,
            'outcome'     => 'success',
            'details'     => [ 'extension' => $ext ],
        ] );

        return true;
    }

    /** Privatize every attachment an upload data source points at. */
    public static function migrate(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $results = [];
        foreach ( (array) $wpdb->get_col( "SELECT configuration FROM {$table}" ) as $raw ) {
            $config = json_decode( (string) $raw, true );
            if ( ! is_array( $config ) ) {
                $config = maybe_unserialize( $raw );
            }
            $id = is_array( $config ) ? (int) ( $config['upload_attachment_id'] ?? 0 ) : 0;
            if ( $id > 0 && ! isset( $results[ $id ] ) ) {
                $r              = self::privatize( $id );
                $results[ $id ] = is_wp_error( $r ) ? $r->get_error_message() : 'ok';
            }
        }

        if ( class_exists( 'MMI_Logger' ) ) {
            MMI_Logger::info( 'Upload source files moved to private storage', [ 'results' => $results ], 'security', 'MMI_Pipeline_Private_Uploads' );
        }
        return $results;
    }

    public static function maybe_migrate(): void {
        if ( (int) MMI_Settings::get( self::MIGRATION_KEY, 0 ) >= self::MIGRATION_VERSION ) {
            return;
        }
        // Set first: a fatal mid-run must not retry on every admin page load.
        MMI_Settings::set( self::MIGRATION_KEY, self::MIGRATION_VERSION );
        self::migrate();
    }

    /** No URL for a private feed — it would expose the private folder name. */
    public static function filter_url( $url, $attachment_id ) {
        return self::is_private( (int) $attachment_id ) ? '' : $url;
    }

    public static function exclude_from_media_query( array $args ): array {
        $args['meta_query']   = (array) ( $args['meta_query'] ?? [] );
        $args['meta_query'][] = [ 'key' => self::META_FLAG, 'compare' => 'NOT EXISTS' ];
        return $args;
    }

    public static function exclude_from_media_list( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() || ( $GLOBALS['pagenow'] ?? '' ) !== 'upload.php' ) {
            return;
        }
        $meta   = (array) $query->get( 'meta_query' );
        $meta[] = [ 'key' => self::META_FLAG, 'compare' => 'NOT EXISTS' ];
        $query->set( 'meta_query', $meta );
    }
}

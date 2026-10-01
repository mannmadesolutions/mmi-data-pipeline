<?php
/**
 * Comment Data Type Handler (Phase 4)
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Comment_Data_Type implements MMI_Data_Type_Handler {

    public function get_type_key(): string {
        return 'comment';
    }

    public function get_label(): string {
        return __( 'Comments', 'mmi-data-pipeline' );
    }

    public function get_field_schema(): array {
        return [
            'comment_ID'           => [ 'label' => 'Comment ID',  'type' => 'integer', 'group' => 'core', 'required' => true ],
            'comment_post_ID'      => [ 'label' => 'Post ID',      'type' => 'integer', 'group' => 'core',  'required' => false ],
            'comment_author'       => [ 'label' => 'Author Name', 'type' => 'string',  'group' => 'core',   'required' => false ],
            'comment_author_email' => [ 'label' => 'Author Email', 'type' => 'string', 'group' => 'core',   'required' => false ],
            'comment_author_url'   => [ 'label' => 'Author URL',  'type' => 'string',  'group' => 'core',   'required' => false ],
            'comment_content'      => [ 'label' => 'Content',     'type' => 'longtext', 'group' => 'core',  'required' => true ],
            'comment_date'         => [ 'label' => 'Date',        'type' => 'datetime', 'group' => 'core',  'required' => false ],
            'comment_approved'     => [ 'label' => 'Status',      'type' => 'string',  'group' => 'core',   'required' => false ],
            'comment_parent'       => [ 'label' => 'Parent Comment ID', 'type' => 'integer', 'group' => 'core', 'required' => false ],
            'user_id'              => [ 'label' => 'User ID',     'type' => 'integer', 'group' => 'core',   'required' => false ],
        ];
    }

    public function get_id_field(): string {
        return 'comment_ID';
    }

    public function get_edit_url( $id ): string {
        return admin_url( 'comment.php?action=editcomment&c=' . absint( $id ) );
    }

    /**
     * @param array $scope Supports: post_id, status ('approve'|'hold'|'spam'|'trash'|'any'), search.
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'number' => $limit,
            'offset' => $offset,
            'status' => ! empty( $scope['status'] ) ? $scope['status'] : 'approve',
            'orderby' => 'comment_ID',
            'order'   => 'ASC',
        ];
        if ( ! empty( $scope['post_id'] ) ) {
            $args['post_id'] = (int) $scope['post_id'];
        }
        if ( ! empty( $scope['search'] ) ) {
            $args['search'] = $scope['search'];
        }

        $query    = new \WP_Comment_Query( $args );
        $comments = $query->comments;

        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'] );
        $count_args['count'] = true;
        $count_query = new \WP_Comment_Query( $count_args );
        $total = (int) $count_query->comments;

        return [ 'records' => $comments, 'total' => $total ];
    }

    /**
     * @param \WP_Comment $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WP_Comment ) {
            return [];
        }

        return [
            'comment_ID'           => $record->comment_ID,
            'comment_post_ID'      => $record->comment_post_ID,
            'comment_author'       => $record->comment_author,
            'comment_author_email' => $record->comment_author_email,
            'comment_author_url'   => $record->comment_author_url,
            'comment_content'      => $record->comment_content,
            'comment_date'         => $record->comment_date,
            'comment_approved'     => $record->comment_approved,
            'comment_parent'       => $record->comment_parent,
            'user_id'              => $record->user_id,
        ];
    }

    public function upsert_record( array $data, ?int $existing_id ): array {
        $args = [];
        $map  = [
            'comment_post_ID'      => 'comment_post_ID',
            'comment_author'       => 'comment_author',
            'comment_author_email' => 'comment_author_email',
            'comment_author_url'   => 'comment_author_url',
            'comment_content'      => 'comment_content',
            'comment_date'         => 'comment_date',
            'comment_approved'     => 'comment_approved',
            'comment_parent'       => 'comment_parent',
            'user_id'              => 'user_id',
        ];
        foreach ( $map as $field => $wp_key ) {
            if ( array_key_exists( $field, $data ) ) {
                $args[ $wp_key ] = $data[ $field ];
            }
        }

        if ( $existing_id ) {
            $args['comment_ID'] = $existing_id;
            $result = wp_update_comment( $args, true );
            if ( is_wp_error( $result ) ) {
                return [ 'success' => false, 'id' => 0, 'message' => $result->get_error_message(), 'action' => 'error' ];
            }
            return [ 'success' => true, 'id' => $existing_id, 'message' => 'updated', 'action' => 'update' ];
        }

        if ( empty( $args['comment_post_ID'] ) || empty( $args['comment_content'] ) ) {
            return [ 'success' => false, 'id' => 0, 'message' => 'comment_post_ID and comment_content are required', 'action' => 'error' ];
        }

        $comment_id = wp_insert_comment( $args );
        if ( ! $comment_id ) {
            return [ 'success' => false, 'id' => 0, 'message' => 'wp_insert_comment() failed', 'action' => 'error' ];
        }

        return [ 'success' => true, 'id' => (int) $comment_id, 'message' => 'created', 'action' => 'create' ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        if ( $primary_value === null || $primary_value === '' || $primary_key_field !== 'comment_ID' ) {
            return null;
        }
        $comment = get_comment( (int) $primary_value );
        return $comment ? (int) $comment->comment_ID : null;
    }

    public function required_capability(): string {
        return 'moderate_comments';
    }
}

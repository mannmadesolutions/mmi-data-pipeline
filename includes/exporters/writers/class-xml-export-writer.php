<?php
/**
 * XML Export Writer
 *
 * Same streaming-across-batches shape as MMI_JSON_Export_Writer: opens with
 * a root <records> element, appends one <record> element per row (reopened
 * in append mode each batch, since batches are separate AJAX requests), and
 * the root is closed via finalize() only on the true final batch.
 *
 * Deliberately hand-writes tags rather than using XMLWriter — XMLWriter's
 * internal writer state is not serializable/resumable across separate PHP
 * requests, which is exactly what this batching model requires. A plain
 * append-mode file handle with manually escaped values is simpler and
 * correct for this constraint (mirrors the CSV/JSON writers' approach).
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_XML_Export_Writer implements MMI_Export_Writer {

    /** @var resource|null */
    private $handle = null;

    public function open( string $file_path, array $columns, bool $is_new ): void {
        $this->handle = fopen( $file_path, $is_new ? 'w' : 'a' );
        if ( $this->handle === false ) {
            $this->handle = null;
            return;
        }
        if ( $is_new ) {
            fwrite( $this->handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n" );
        }
    }

    public function append_row( array $row ): void {
        if ( ! $this->handle ) {
            return;
        }
        fwrite( $this->handle, "  <record>\n" );
        foreach ( $row as $field => $value ) {
            $tag = $this->sanitize_tag_name( (string) $field );
            $text = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
            fwrite( $this->handle, "    <{$tag}>" . $this->escape( $text ) . "</{$tag}>\n" );
        }
        fwrite( $this->handle, "  </record>\n" );
    }

    /**
     * Closes the file handle only — does NOT write the closing </records>,
     * since that must only happen once, on the final batch.
     */
    public function close(): void {
        if ( $this->handle ) {
            fclose( $this->handle );
            $this->handle = null;
        }
    }

    /**
     * Append the closing </records> — call exactly once, after the last
     * batch of a run has been written and closed.
     */
    public function finalize( string $file_path ): void {
        $handle = fopen( $file_path, 'a' );
        if ( $handle ) {
            fwrite( $handle, '</records>' );
            fclose( $handle );
        }
    }

    /**
     * XML element names can't contain most punctuation (field keys here
     * include ':' from taxonomy/meta-prefixed schema keys like 'tax:product_cat'
     * or 'meta:_custom_field') — replace anything not alphanumeric/underscore/
     * hyphen with an underscore, and ensure the tag doesn't start with a digit.
     */
    private function sanitize_tag_name( string $name ): string {
        $tag = preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $name );
        if ( $tag === '' || is_numeric( $tag[0] ) ) {
            $tag = 'field_' . $tag;
        }
        return $tag;
    }

    private function escape( string $value ): string {
        return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    public function get_extension(): string {
        return 'xml';
    }
}

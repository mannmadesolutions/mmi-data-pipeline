<?php
/**
 * MMI_Model — base ORM for custom tables (bundled/vendored copy).
 *
 * Identical to the original mmi-hub/includes/models/class-model.php this was
 * vendored from. Already fully self-contained ($wpdb-only, no mmi-hub-specific
 * constant) — degrades gracefully (returns null/empty/false, never fatals) if
 * a subclass's table doesn't exist, same as MMI_Settings. See ADR-0006. Do not
 * hand-edit this file in a single plugin; edit the canonical source and
 * re-sync to every plugin that bundles it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

abstract class MMI_Model {

	protected static $table_name = '';
	protected static $primary_key = 'id';

	protected $data = array();
	protected $dirty = array();
	protected $original = array();

	public function __construct( $data = array() ) {
		$this->data     = $data;
		$this->original = $data;
	}

	protected static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . static::$table_name;
	}

	/**
	 * @return static|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = static::get_table_name();
		$pk    = static::$primary_key;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$pk} = %d", $id ),
			ARRAY_A
		);

		return $row ? new static( $row ) : null;
	}

	/**
	 * @return static[]
	 */
	public static function where( $conditions, $limit = null, $offset = null ) {
		global $wpdb;
		$table = static::get_table_name();

		$where_clauses = array();
		$values        = array();

		foreach ( $conditions as $key => $value ) {
			// Column names are interpolated, not prepared — accept identifiers
			// only. An invalid one fails closed (no rows) rather than being
			// dropped, which would silently widen the query.
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
				return array();
			}
			if ( is_null( $value ) ) {
				$where_clauses[] = "`{$key}` IS NULL";
			} else {
				$where_clauses[] = "`{$key}` = %s";
				$values[]        = $value;
			}
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$sql       = "SELECT * FROM {$table}";

		if ( ! empty( $where_sql ) ) {
			$sql .= " WHERE {$where_sql}";
		}

		if ( $limit !== null ) {
			$sql .= ' LIMIT ' . (int) $limit;

			if ( $offset !== null ) {
				$sql .= ' OFFSET ' . (int) $offset;
			}
		}

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			function ( $row ) {
				return new static( $row );
			},
			(array) $rows
		);
	}

	/**
	 * @return static|null
	 */
	public static function find_one_by( $conditions ) {
		$results = static::where( $conditions, 1 );
		return $results ? $results[0] : null;
	}

	/**
	 * @return static[]
	 */
	public static function all( $limit = null, $offset = null ) {
		return static::where( array(), $limit, $offset );
	}

	protected static $timestamp_columns_cache = array();

	protected static function has_timestamp_columns( $table ) {
		if ( ! isset( self::$timestamp_columns_cache[ $table ] ) ) {
			global $wpdb;
			$columns                                    = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			self::$timestamp_columns_cache[ $table ] = array(
				'created_at' => in_array( 'created_at', $columns, true ),
				'updated_at' => in_array( 'updated_at', $columns, true ),
			);
		}
		return self::$timestamp_columns_cache[ $table ];
	}

	public function save() {
		global $wpdb;
		$table   = static::get_table_name();
		$pk      = static::$primary_key;
		$has_ts  = static::has_timestamp_columns( $table );

		if ( $has_ts['updated_at'] ) {
			$this->data['updated_at'] = current_time( 'mysql' );
		}

		if ( empty( $this->data[ $pk ] ) ) {
			if ( $has_ts['created_at'] ) {
				$this->data['created_at'] = current_time( 'mysql' );
			}

			$result = $wpdb->insert( $table, $this->data );

			if ( $result ) {
				$this->data[ $pk ] = $wpdb->insert_id;
				$this->original    = $this->data;
				$this->dirty       = array();
				return $this;
			}

			return false;
		} else {
			if ( empty( $this->dirty ) ) {
				return $this; // No changes
			}

			$update_data = array();
			foreach ( $this->dirty as $field ) {
				$update_data[ $field ] = $this->data[ $field ];
			}
			if ( $has_ts['updated_at'] ) {
				$update_data['updated_at'] = $this->data['updated_at'];
			}

			$result = $wpdb->update(
				$table,
				$update_data,
				array( $pk => $this->data[ $pk ] )
			);

			if ( $result !== false ) {
				$this->original = $this->data;
				$this->dirty    = array();
				return $this;
			}

			return false;
		}
	}

	public function delete() {
		global $wpdb;
		$table = static::get_table_name();
		$pk    = static::$primary_key;

		if ( ! empty( $this->data[ $pk ] ) ) {
			return $wpdb->delete( $table, array( $pk => $this->data[ $pk ] ) );
		}

		return false;
	}

	public function refresh() {
		$pk = static::$primary_key;

		if ( ! empty( $this->data[ $pk ] ) ) {
			$fresh = static::find( $this->data[ $pk ] );
			if ( $fresh ) {
				$this->data      = $fresh->data;
				$this->original  = $fresh->data;
				$this->dirty     = array();
			}
		}

		return $this;
	}

	public function exists() {
		$pk = static::$primary_key;
		return ! empty( $this->data[ $pk ] );
	}

	public function is_dirty( $field = null ) {
		if ( $field === null ) {
			return ! empty( $this->dirty );
		}

		return in_array( $field, $this->dirty );
	}

	public function __get( $key ) {
		return $this->data[ $key ] ?? null;
	}

	public function __set( $key, $value ) {
		if ( ! isset( $this->data[ $key ] ) || $this->data[ $key ] !== $value ) {
			$this->data[ $key ] = $value;

			if ( ! in_array( $key, $this->dirty ) ) {
				$this->dirty[] = $key;
			}
		}
	}

	public function __isset( $key ) {
		return isset( $this->data[ $key ] );
	}

	public function to_array() {
		return $this->data;
	}

	public function to_json() {
		return json_encode( $this->data );
	}
}

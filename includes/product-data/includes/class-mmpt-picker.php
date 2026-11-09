<?php
/**
 * Claude picks a title description from vendor copy — as a verbatim span,
 * never written text (user decision 2026-09-25). Used where the
 * "<Product> is a <phrase>" pattern (Descriptions::pick_by_pattern) finds
 * nothing usable.
 *
 * Runs ONLY on the owner's Claude subscription, through the Claude Code CLI
 * in headless mode (the CLI's headless mode) — never an API key (user rule 2026-09-26:
 * all Claude APIs are disabled). run() is called from WP-CLI (`wp mmpt pick`,
 * driven by a system cron line), never from a web request or Action
 * Scheduler, so no PHP-FPM worker ever waits on Claude. Each CLI call carries
 * PER_CALL products and answers for all of them in one structured-JSON reply.
 *
 * Every returned phrase is re-checked in code — it must occur word-for-word
 * in the vendor copy and pass Descriptions::acceptable() — or it is
 * discarded. Nothing here writes a product; picks land as `candidate` rows
 * for review.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Picker {

	const MODEL      = 'claude-sonnet-5';
	const PER_CALL   = 20;
	const CALLS_MAX  = 10;
	const TIMEOUT_S  = 600;
	const STATE      = 'mmi_admin_product_titles_picker_state';
	const BLOCK_CAP  = 1500;
	const BLOCKS_MAX = 4;
	const MAX_LOAD   = 2.5;
	const RETRY_NOTE = 'cli: no answer, retry';

	/** Retired Message Batches poll hook; unscheduled on sight. */
	const OLD_POLL_HOOK = 'mmpt_picker_poll';

	const SYSTEM = 'You choose the short description that goes into an online store\'s product titles. You never write a description yourself: for each product you copy an exact span of consecutive words from the vendor copy you are given for that product, so every word in the title comes from the vendor. Answer for every product you are given.';

	const SCHEMA = [
		'type'                 => 'object',
		'properties'           => [
			'results' => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'properties'           => [
						'product'    => [ 'type' => 'integer', 'description' => 'The product id attribute.' ],
						'candidates' => [
							'type'        => 'array',
							'description' => 'Up to 3 spans, best first. Empty if none qualifies.',
							'items'       => [
								'type'                 => 'object',
								'properties'           => [
									'phrase' => [ 'type' => 'string', 'description' => 'The exact span, copied character-for-character from one block of this product.' ],
									'block'  => [ 'type' => 'integer', 'description' => 'Number of the block it was copied from.' ],
								],
								'required'             => [ 'phrase', 'block' ],
								'additionalProperties' => false,
							],
						],
						'reason'     => [ 'type' => 'string', 'description' => 'One short sentence about the first candidate, or why there is none.' ],
					],
					'required'             => [ 'product', 'candidates', 'reason' ],
					'additionalProperties' => false,
				],
			],
		],
		'required'             => [ 'results' ],
		'additionalProperties' => false,
	];

	public static function register_hooks(): void {
		// The Batches-API poll chain is gone; a leftover action would only fail.
		add_action( self::OLD_POLL_HOOK, '__return_null' );
	}

	/** Path to the Claude Code CLI (kept for callers; Claude::bin() owns it). */
	public static function bin(): string {
		return \MMI_AI_Claude_Code::bin();
	}

	/**
	 * Pick descriptions for up to $calls × PER_CALL products whose row is
	 * `needs_ai` (copy exists, the pattern found nothing) — or only $ids.
	 * CLI-only: refuses to run inside a web request.
	 */
	public static function run( int $calls = 1, array $ids = [] ): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'error' => 'The picker runs from WP-CLI only (wp mmpt pick).' ];
		}
		// Descriptions only reach titles through the Description rule: with it switched off
		// (Rules tab, "In use"), the cron run makes no Claude calls. A run for named ids still works.
		if ( ! $ids && ! apply_filters( 'mmi_pipeline_title_descriptions_on', true ) ) { // this site: the title composer's Description rule
			return [ 'skipped' => 'Description rule is switched off' ];
		}
		$pre = \MMI_AI::preflight(); // set up, under the cap, and (this site) the plan's live usage
		if ( ! $pre['ok'] ) {
			return [ 'skipped' => $pre['reason'] ];
		}
		$load = function_exists( 'sys_getloadavg' ) ? (float) ( sys_getloadavg()[0] ?? 0 ) : 0.0;
		if ( $load > self::MAX_LOAD ) {
			return [ 'skipped' => 'server load ' . $load ];
		}
		$lock = fopen( trailingslashit( sys_get_temp_dir() ) . 'mmpt-picker.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return [ 'skipped' => 'another picker run is in progress' ];
		}
		$done = [ 'picked' => 0, 'none' => 0, 'retry' => 0, 'calls' => 0, 'errors' => 0 ];
		try {
			for ( $i = 0; $i < max( 1, min( $calls, self::CALLS_MAX ) ); $i++ ) {
				$pids = self::next_ids( $ids );
				if ( ! $pids ) {
					break;
				}
				$r = self::call( $pids );
				$done['calls']++;
				if ( isset( $r['error'] ) ) {
					$done['errors']++;
					Module::warn( 'Description pick call failed', [ 'error' => $r['error'], 'products' => count( $pids ) ] );
					break; // Usage limit or CLI trouble: stop this run, the next cron tick retries.
				}
				foreach ( $r as $k => $n ) {
					$done[ $k ] += $n;
				}
				if ( $ids ) {
					break;
				}
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		$st  = Module::state( self::STATE );
		$tot = (array) ( $st['totals'] ?? [] );
		foreach ( [ 'picked', 'none', 'calls', 'errors' ] as $k ) {
			$tot[ $k ] = (int) ( $tot[ $k ] ?? 0 ) + $done[ $k ];
		}
		Module::set_state( self::STATE, [ 'engine' => 'cli', 'last_run' => current_time( 'mysql' ), 'last_result' => $done, 'totals' => $tot ] );
		if ( $done['calls'] ) {
			Module::info( 'Description picks collected', $done );
		}
		return $done;
	}

	/** Next PER_CALL waiting products; rows the model skipped go to the back. */
	private static function next_ids( array $ids ): array {
		global $wpdb;
		$t     = Descriptions::table();
		$where = "status = 'needs_ai'" . Exclusions::sql_not_excluded();
		if ( $ids ) {
			$where .= ' AND product_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$t} WHERE {$where} ORDER BY note = %s, product_id LIMIT %d", self::RETRY_NOTE, self::PER_CALL ) )
		);
	}

	/**
	 * One CLI call for a set of products. @return array counts, or ['error' => …]
	 */
	private static function call( array $pids ): array {
		$inputs = array_filter( Descriptions::inputs( $pids ), static fn( $in ) => (bool) $in['blocks'] );
		foreach ( array_diff( $pids, array_keys( $inputs ) ) as $gone ) {
			Descriptions::upsert( $gone, [ 'status' => 'pending', 'note' => 'no copy blocks' ] );
		}
		if ( ! $inputs ) {
			return [];
		}
		$out = self::cli( self::prompt( $inputs ) );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$st  = Module::state( self::STATE );
		$use = (array) ( $st['usage'] ?? [] );
		$use['notional_usd'] = round( (float) ( $use['notional_usd'] ?? 0 ) + (float) ( $out['total_cost_usd'] ?? 0 ), 4 );
		$use['input']        = (int) ( $use['input'] ?? 0 ) + (int) ( $out['usage']['input_tokens'] ?? 0 ) + (int) ( $out['usage']['cache_read_input_tokens'] ?? 0 ) + (int) ( $out['usage']['cache_creation_input_tokens'] ?? 0 );
		$use['output']       = (int) ( $use['output'] ?? 0 ) + (int) ( $out['usage']['output_tokens'] ?? 0 );
		Module::set_state( self::STATE, [ 'usage' => $use ] );

		$answers = [];
		foreach ( (array) ( $out['structured_output']['results'] ?? [] ) as $a ) {
			$answers[ (int) ( $a['product'] ?? 0 ) ] = $a;
		}
		$done = [ 'picked' => 0, 'none' => 0, 'retry' => 0 ];
		foreach ( $inputs as $id => $in ) {
			if ( ! isset( $answers[ $id ] ) ) {
				Descriptions::upsert( $id, [ 'status' => 'needs_ai', 'note' => self::RETRY_NOTE ] );
				$done['retry']++;
				continue;
			}
			$done[ self::take( $id, $answers[ $id ], $in ) ]++;
		}
		return $done;
	}

	/**
	 * One structured call through the store's AI setup (MMI_AI; on this site the Claude plan, through its
	 * guarded runner). The model, time and thinking are this picker's, as before the move to MMI_AI.
	 * @return array { structured_output } or { error }
	 */
	private static function cli( string $prompt ): array {
		$r = \MMI_AI::ask( [ 'system' => self::SYSTEM, 'content' => $prompt, 'schema' => self::SCHEMA, 'model' => self::MODEL, 'timeout' => self::TIMEOUT_S, 'thinking' => true, 'max_tokens' => 16000, 'purpose' => 'picker' ] );
		return is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'structured_output' => $r['answer'] ];
	}

	/** Verify one product's answer and store it. @return string 'picked'|'none' */
	private static function take( int $id, array $answer, array $in ): string {
		$phrase   = '';
		$source   = null;
		$rejected = [];
		foreach ( (array) ( $answer['candidates'] ?? [] ) as $c ) {
			$p = Descriptions::trim_opinion( Descriptions::strip_article( trim( (string) ( $c['phrase'] ?? '' ), " \"'“”.,;:" ) ) );
			$b = $in['blocks'][ (int) ( $c['block'] ?? 0 ) - 1 ] ?? null;
			if ( $p === '' || ! $b ) {
				continue;
			}
			if ( ! Descriptions::verify( $p, $b['text'] ) ) {
				$rejected[] = "not verbatim: {$p}";
			} elseif ( ! Descriptions::acceptable( $p, $in['title'] ) ) {
				$rejected[] = "guard: {$p}";
			} else {
				$phrase = $p;
				$source = $b;
				break;
			}
		}
		if ( $phrase === '' ) {
			$why = $rejected ? implode( ' | ', $rejected ) : 'no qualifying span: ' . ( $answer['reason'] ?? '' );
			Descriptions::upsert( $id, [ 'status' => 'none', 'method' => 'ai', 'phrase' => '', 'picked_at' => current_time( 'mysql' ), 'note' => mb_substr( $why, 0, 250 ) ] );
			return 'none';
		}
		Descriptions::upsert(
			$id,
			[
				'status'      => 'candidate',
				'phrase'      => mb_substr( $phrase, 0, 120 ),
				'method'      => 'ai',
				'source_type' => $source['type'],
				'source_url'  => mb_substr( $source['url'], 0, 500 ),
				'excerpt'     => $source['text'],
				'picked_at'   => current_time( 'mysql' ),
				'note'        => mb_substr( (string) ( $answer['reason'] ?? '' ), 0, 250 ),
			]
		);
		return 'picked';
	}

	/**
	 * Re-run the guard after a guard change, both ways — no Claude calls:
	 * stored guard-rejections (spans kept in `note`) are re-verified verbatim
	 * against the current copy and promoted if they now pass, and unreviewed
	 * candidates the guard now refuses are demoted to `none`.
	 */
	public static function recheck_rejections(): array {
		global $wpdb;
		$t    = Descriptions::table();
		$rows = $wpdb->get_results( "SELECT product_id, note FROM {$t} WHERE method = 'ai' AND status = 'none' AND note LIKE '%guard:%'", ARRAY_A );
		$out  = [ 'checked' => count( $rows ), 'promoted' => 0 ];
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$inputs = Descriptions::inputs( array_column( $chunk, 'product_id' ) );
			foreach ( $chunk as $r ) {
				$in = $inputs[ (int) $r['product_id'] ] ?? null;
				preg_match_all( '/guard(?: \([^)]*\))?: ([^|]+)/', (string) $r['note'], $m );
				foreach ( $in ? $m[1] : [] as $p ) {
					$p = Descriptions::trim_opinion( Descriptions::strip_article( trim( $p ) ) );
					foreach ( $in['blocks'] as $b ) {
						if ( Descriptions::verify( $p, $b['text'] ) && Descriptions::acceptable( $p, $in['title'] ) ) {
							Descriptions::upsert( (int) $r['product_id'], [ 'status' => 'candidate', 'phrase' => mb_substr( $p, 0, 120 ), 'source_type' => $b['type'], 'source_url' => mb_substr( $b['url'], 0, 500 ), 'excerpt' => $b['text'], 'note' => 'promoted on recheck: ' . mb_substr( (string) $r['note'], 0, 200 ) ] );
							$out['promoted']++;
							continue 3;
						}
					}
				}
			}
		}
		// And the other way: an unreviewed Claude candidate the current guard would refuse goes back to `none`.
		$cands = $wpdb->get_results( "SELECT product_id, phrase FROM {$t} WHERE method = 'ai' AND status = 'candidate'", ARRAY_A );
		$out['demoted'] = 0;
		foreach ( array_chunk( $cands, 200 ) as $chunk ) {
			$inputs = Descriptions::inputs( array_column( $chunk, 'product_id' ) );
			foreach ( $chunk as $r ) {
				$title = $inputs[ (int) $r['product_id'] ]['title'] ?? '';
				if ( ! Descriptions::acceptable( (string) $r['phrase'], $title ) ) {
					Descriptions::upsert( (int) $r['product_id'], [ 'status' => 'none', 'phrase' => '', 'note' => mb_substr( 'guard (recheck): ' . $r['phrase'], 0, 250 ) ] );
					$out['demoted']++;
				}
			}
		}
		return $out;
	}

	/** One prompt covering every product in the call, each with its own numbered blocks. */
	public static function prompt( array $inputs ): string {
		$products = [];
		foreach ( $inputs as $id => $in ) {
			$parts = [];
			foreach ( array_slice( $in['blocks'], 0, self::BLOCKS_MAX ) as $i => $b ) {
				$label   = $b['type'] === 'vendor_page' ? 'vendor product page' . ( $b['url'] !== '' ? ' ' . $b['url'] : '' ) : str_replace( '_', ' ', $b['type'] );
				$parts[] = sprintf( "<block number=\"%d\" source=\"%s\">\n%s\n</block>", $i + 1, esc_attr( $label ), mb_substr( $b['text'], 0, self::BLOCK_CAP ) );
			}
			$products[] = sprintf( "<product id=\"%d\" title=\"%s\" brand=\"%s\">\n%s\n</product>", $id, esc_attr( $in['title'] ), esc_attr( $in['brand'] ), implode( "\n", $parts ) );
		}
		return implode( "\n\n", $products ) . "\n\n" . self::instructions();
	}

	/**
	 * What the call is asked to do with the products above. A store can word it its own way:
	 * filter mmi_pipeline_ai_prompt ( $text, 'title_description' ); this site answers in SitePolicy.
	 */
	public static function instructions(): string {
		$default = "For EACH product above, copy up to 3 spans of 2 to 6 consecutive words, each exactly as written in one of that product's own blocks, that say what kind of product it is — the way \"revolutionary algorithmic reverb\" does for a reverb plugin, \"multimode filter plug-in\" for a filter, or \"minimal electronic music sound library\" for a sound library. Best first. Block numbers refer to that product's blocks only.\n"
			. "A good span names the concrete kind of thing (reverb, tape recorder plugin, cello library, drum kit expansion, notation software, course…) with what makes it specific (instrument, genre, technique). Avoid spans that are only a count or size (\"100 patches\", \"6.59GB\"), only praise (\"groundbreaking\", \"the ultimate\", \"industry-leading\"), or vague (\"crafted collection\", \"unique effect\").\n"
			. "It must say what the product IS, not one of its features (for a synthesizer, \"analog synthesizer\", not \"intuitive step sequencer\"), and describe the product itself: not an instrument or plugin it is an add-on for, not its maker, not hardware that a course teaches. Leave out the product's own name, prices and version numbers. Leave out opinions too (user-friendly, versatile, intuitive, premium, powerful, essential, creative…): say what it is, not how good it is.\n"
			. "Every span must add something the title does not already say: for a product titled \"Vintage Tube EQs\", \"EQ plugins\" only repeats it, while a span naming the kind of EQ or what it models adds to it. A span made only of title words plus plug-in, software or format names (VST, AU, AAX) does not count.\n"
			. 'Return one result per product id. If nothing qualifies for a product, give it no candidates.';
		return (string) apply_filters( 'mmi_pipeline_ai_prompt', $default, 'title_description' );
	}
}

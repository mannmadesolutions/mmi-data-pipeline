<?php
/**
 * MMI_AI — one AI provider setup for every MMI plugin: OpenAI, Anthropic
 * (Claude), Google Gemini, Mistral or DeepSeek, each connected with an API key
 * or, where the provider allows its plans to be used this way, with a plan
 * (plan_info()). Today that is Claude Pro or Max, through Claude Code signed in
 * on the site's own server (MMI_AI_Claude_Code).
 *
 * Lifted from mmi-seo (1.22.x) so mmi-seo, mmi-data-pipeline and any other
 * plugin share one provider, one key per provider and one daily cap: a store
 * sets AI up once, whichever plugin it installed first. Settings live under
 * neutral `mmi_ai_*` keys; a site that set AI up in mmi-seo before has those
 * values copied across once (move_legacy_settings()), and the old keys stay.
 *
 * Cost rules built in, because every request is billed by the provider to
 * whoever owns the key:
 *   - nothing is sent until a key is saved, a provider is chosen, and a person
 *     clicks or runs a bulk action in the calling plugin;
 *   - a plan is only used while a person is there (one call at a time);
 *   - a daily request cap (default 100) stops everything for the rest of the
 *     day, bulk included, across every plugin;
 *   - requests and the tokens each provider reports are counted per day, so
 *     the bill holds no surprises.
 * Checking a key lists the provider's models, which is free.
 *
 * Two wire formats cover the five providers: Anthropic's Messages API, and
 * OpenAI's chat completions, which Gemini (its OpenAI-compatible endpoint),
 * Mistral and DeepSeek also speak. DeepSeek's API can't see pictures.
 *
 * Keys are stored through MMI_Settings under secret-named keys, so they are
 * encrypted at rest (MMI_Credentials), and never reach the browser.
 *
 * Seams (neutral names, ADR-0012 addendum):
 *  - 'mmi_ai_settings_location'  where people set AI up, for messages ("SEO → AI").
 *  - 'mmi_ai_claude_code_run'    see MMI_AI_Claude_Code.
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source in
 * mmi-admin/lib/mmi-shared/ and run lib/sync-to-plugins.sh.
 *
 * @package MMI_Shared
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_AI' ) ) :

	class MMI_AI {

		const LOG_CATEGORY = 'integrations';
		const TIMEOUT      = 30;
		const MAX_TOKENS   = 2000; // generous: reasoning models spend part of it thinking; only used tokens are billed
		const USAGE_DAYS   = 31;
		const DEFAULT_CAP  = 100;

		/** Settings are stored as PREFIX . key; mmi-seo stored them as LEGACY_PREFIX . key. */
		const PREFIX        = 'mmi_ai_';
		const LEGACY_PREFIX = 'mmi_seo_ai_';
		const MOVED_FLAG    = 'mmi_ai_settings_moved';

		const OPT_PROVIDER = 'provider';
		const OPT_LANGUAGE = 'language';
		const OPT_CAP      = 'daily_limit';
		const OPT_USAGE    = 'usage';

		/** How mmi-seo 1.21.0 stored "Claude subscription" as its own provider; read once and moved to anthropic + plan. */
		const LEGACY_PLAN = 'claude_code';
		const ACCESS_PLAN = 'plan';
		const ACCESS_KEY  = 'key';

		/** Throttler profile: one request at a time, with room for the provider's own limits. */
		const THROTTLE = array( 'min_gap_ms' => 500, 'backoff_ms' => 5000, 'max_backoff_ms' => 60000, 'transient_ttl' => 120 );

		/** Models never offered: they can't write text. */
		const NOT_TEXT = '/embed|tts|whisper|dall-e|image|audio|realtime|moderation|transcri|search|codex|computer-use|ocr|davinci|babbage|aqa|imagen|veo|learnlm|gemma/i';

		/**
		 * slug => wire format, API base, whether it reads pictures, preferred model
		 * patterns (first match among the account's models wins), where to get a key.
		 */
		const PROVIDERS = array(
			'openai'    => array(
				'name'    => 'OpenAI',
				'format'  => 'openai',
				'base'    => 'https://api.openai.com/v1/',
				'vision'  => true,
				'prefer'  => array( '/^gpt-5(\.\d+)?-mini$/', '/^gpt-4\.1-mini$/', '/^gpt-4o-mini$/', '/^gpt-.*mini$/' ),
				'keys'    => 'https://platform.openai.com/api-keys',
				'pricing' => 'https://openai.com/api/pricing/',
			),
			'anthropic' => array(
				'name'    => 'Claude (Anthropic)',
				'format'  => 'anthropic',
				'base'    => 'https://api.anthropic.com/v1/',
				'vision'  => true,
				'prefer'  => array( '/haiku/', '/sonnet/' ),
				'keys'    => 'https://console.anthropic.com/settings/keys',
				'pricing' => 'https://www.anthropic.com/pricing#api',
			),
			'gemini'    => array(
				'name'    => 'Google Gemini',
				'format'  => 'openai',
				'base'    => 'https://generativelanguage.googleapis.com/v1beta/openai/',
				'vision'  => true,
				'prefer'  => array( '/^gemini-[\d.]+-flash$/', '/^gemini-flash-latest$/', '/flash(?!.*(lite|live|exp|preview|thinking))/', '/flash/' ),
				'keys'    => 'https://aistudio.google.com/apikey',
				'pricing' => 'https://ai.google.dev/gemini-api/docs/pricing',
			),
			'mistral'   => array(
				'name'         => 'Mistral',
				'format'       => 'openai',
				'base'         => 'https://api.mistral.ai/v1/',
				'vision'       => true,
				'image_string' => true, // Mistral takes image_url as a plain string
				'prefer'       => array( '/^mistral-small-latest$/', '/^mistral-medium-latest$/', '/^pixtral-large-latest$/' ),
				'keys'         => 'https://console.mistral.ai/api-keys',
				'pricing'      => 'https://mistral.ai/pricing#api-pricing',
			),
			'deepseek'  => array(
				'name'    => 'DeepSeek',
				'format'  => 'openai',
				'base'    => 'https://api.deepseek.com/',
				'vision'  => false,
				'prefer'  => array( '/^deepseek-chat$/' ),
				'keys'    => 'https://platform.deepseek.com/api_keys',
				'pricing' => 'https://api-docs.deepseek.com/quick_start/pricing',
			),
		);

		/* ── Settings ────────────────────────────────────────────────────── */

		/** A setting ('provider', 'openai_api_key', …). */
		public static function get( string $key, $default = false ) {
			self::move_legacy_settings();
			return MMI_Settings::get( self::PREFIX . $key, $default );
		}

		public static function set( string $key, $value ): bool {
			self::move_legacy_settings();
			return MMI_Settings::set( self::PREFIX . $key, $value );
		}

		/** Every setting key, for moving them and for tests. */
		public static function setting_keys(): array {
			$keys = array( self::OPT_PROVIDER, self::OPT_LANGUAGE, self::OPT_CAP, self::OPT_USAGE, 'claude_code_model', 'claude_code_paused' );
			foreach ( array_keys( self::PROVIDERS ) as $slug ) {
				array_push( $keys, $slug . '_access', $slug . '_api_key', $slug . '_model', $slug . '_models' );
			}
			return $keys;
		}

		/**
		 * Once per site: copy AI settings mmi-seo stored before 1.48.0 to the
		 * shared keys. Only fills a shared key that isn't there yet; the old keys
		 * are left as they were. After this, only the shared keys are read, so a
		 * key cleared later can't come back from an old copy.
		 */
		public static function move_legacy_settings(): void {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;
			if ( MMI_Settings::get( self::MOVED_FLAG, '' ) ) {
				return;
			}
			$missing = new stdClass();
			foreach ( self::setting_keys() as $key ) {
				$old = MMI_Settings::get( self::LEGACY_PREFIX . $key, $missing );
				if ( $old !== $missing && MMI_Settings::get( self::PREFIX . $key, $missing ) === $missing ) {
					MMI_Settings::set( self::PREFIX . $key, $old );
				}
			}
			MMI_Settings::set( self::MOVED_FLAG, gmdate( 'c' ) );
		}

		/** Where people set AI up, named in messages. */
		public static function where(): string {
			return (string) apply_filters( 'mmi_ai_settings_location', __( 'the AI settings', 'mmi-shared' ) );
		}

		public static function provider(): string {
			$slug = (string) self::get( self::OPT_PROVIDER, '' );
			if ( $slug === self::LEGACY_PLAN ) {
				self::set( self::OPT_PROVIDER, 'anthropic' );
				self::set( 'anthropic_access', self::ACCESS_PLAN );
				$slug = 'anthropic';
			}
			return isset( self::PROVIDERS[ $slug ] ) ? $slug : '';
		}

		public static function name( string $slug ): string {
			return self::PROVIDERS[ $slug ]['name'] ?? $slug;
		}

		/**
		 * Whether a provider's plan can be used here instead of an API key, and why
		 * not when it can't. Only plans the provider allows to be used from the
		 * site's own server, run headless with every tool off, are offered.
		 *
		 * @return array{available:bool, label:string, note:string}
		 */
		public static function plan_info( string $slug ): array {
			switch ( $slug ) {
				case 'anthropic':
					return array( 'available' => true, 'label' => __( 'Claude Pro or Max plan', 'mmi-shared' ), 'note' => __( 'Uses Claude Code signed in on this server; nothing is billed per request.', 'mmi-shared' ) );
				case 'openai':
					return array( 'available' => false, 'label' => __( 'ChatGPT plan', 'mmi-shared' ), 'note' => __( 'Not offered yet. A ChatGPT plan runs through OpenAI\'s Codex CLI, whose command tools can\'t yet be confirmed switched off, and page text must never be able to make it read files on the server. Use an API key.', 'mmi-shared' ) );
				case 'gemini':
					return array( 'available' => false, 'label' => __( 'Google AI Pro or Ultra plan', 'mmi-shared' ), 'note' => __( 'Google doesn\'t allow its consumer plans to be used from other applications (Antigravity CLI terms), so Gemini works with an API key.', 'mmi-shared' ) );
				case 'mistral':
					return array( 'available' => false, 'label' => __( 'Le Chat Pro plan', 'mmi-shared' ), 'note' => __( 'Not offered yet. Mistral allows Le Chat Pro through its Vibe CLI; it is added once it has been tested on a server. Use an API key.', 'mmi-shared' ) );
			}
			return array( 'available' => false, 'label' => __( 'Plan', 'mmi-shared' ), 'note' => __( 'This provider has no plan that can be used this way, so it works with an API key.', 'mmi-shared' ) );
		}

		/** How a provider connects: 'plan' where its plan is offered and chosen, else 'key'. */
		public static function access( string $slug ): string {
			$plan = self::plan_info( $slug )['available'] && self::get( $slug . '_access', self::ACCESS_KEY ) === self::ACCESS_PLAN;
			return $plan ? self::ACCESS_PLAN : self::ACCESS_KEY;
		}

		/** Writing on a plan rather than a billed API key. */
		public static function on_subscription(): bool {
			$slug = self::provider();
			return $slug !== '' && self::access( $slug ) === self::ACCESS_PLAN;
		}

		/** Who writes, for people: "Claude Pro or Max plan" or the provider's name. */
		public static function label(): string {
			$slug = self::provider();
			return self::on_subscription() ? self::plan_info( $slug )['label'] : self::name( $slug );
		}

		/** Bulk runs need an API key: a plan writes one item at a time, by a person's click. */
		public static function bulk_allowed(): bool {
			return ! self::on_subscription() && self::ready();
		}

		/** The saved key for a provider ('' when none). Server-side only. */
		public static function key( string $slug ): string {
			return isset( self::PROVIDERS[ $slug ] ) ? trim( (string) self::get( $slug . '_api_key', '' ) ) : '';
		}

		public static function model( string $slug ): string {
			if ( self::access( $slug ) === self::ACCESS_PLAN ) {
				return MMI_AI_Claude_Code::model();
			}
			return trim( (string) self::get( $slug . '_model', '' ) );
		}

		/** Models the provider listed for this key at the last check. */
		public static function models( string $slug ): array {
			$models = self::get( $slug . '_models', array() );
			return is_array( $models ) ? $models : array();
		}

		/** Ready to write: a provider is chosen and has a key and a model (or a signed-in plan). */
		public static function ready(): bool {
			$slug = self::provider();
			if ( $slug !== '' && self::access( $slug ) === self::ACCESS_PLAN ) {
				return MMI_AI_Claude_Code::ready();
			}
			return $slug !== '' && self::key( $slug ) !== '' && self::model( $slug ) !== '';
		}

		public static function sees_pictures(): bool {
			return self::on_subscription() || ! empty( self::PROVIDERS[ self::provider() ]['vision'] );
		}

		public static function daily_cap(): int {
			return max( 0, (int) self::get( self::OPT_CAP, self::DEFAULT_CAP ) );
		}

		/** The language replies are written in: the setting, or the site's own. */
		public static function language(): string {
			$set = trim( (string) self::get( self::OPT_LANGUAGE, '' ) );
			return $set !== '' ? $set : sprintf( 'the language of the locale "%s"', get_locale() );
		}

		/* ── Usage ───────────────────────────────────────────────────────── */

		/** date => { requests, input, output, failed }, newest last. */
		public static function usage(): array {
			$usage = self::get( self::OPT_USAGE, array() );
			$usage = is_string( $usage ) ? json_decode( $usage, true ) : $usage;
			return is_array( $usage ) ? $usage : array();
		}

		public static function today(): array {
			return self::usage()[ wp_date( 'Y-m-d' ) ] ?? array( 'requests' => 0, 'input' => 0, 'output' => 0, 'failed' => 0 );
		}

		/** Requests still allowed today. */
		public static function remaining_today(): int {
			return max( 0, self::daily_cap() - (int) self::today()['requests'] );
		}

		private static function record_usage( bool $ok, int $input, int $output ): void {
			$usage = self::usage();
			$day   = wp_date( 'Y-m-d' );
			$row   = $usage[ $day ] ?? array( 'requests' => 0, 'input' => 0, 'output' => 0, 'failed' => 0 );
			$row['requests']++;
			$row['input']  += $input;
			$row['output'] += $output;
			if ( ! $ok ) {
				$row['failed']++;
			}
			$usage[ $day ] = $row;
			ksort( $usage );
			self::set( self::OPT_USAGE, wp_json_encode( array_slice( $usage, -self::USAGE_DAYS, null, true ) ) );
		}

		/* ── Provider API ────────────────────────────────────────────────── */

		/**
		 * The text-writing models a key can use, newest first as the provider lists
		 * them. Free: listing models is not billed.
		 *
		 * @return string[]|WP_Error
		 */
		public static function list_models( string $slug, string $key ) {
			$p = self::PROVIDERS[ $slug ] ?? null;
			if ( ! $p ) {
				return new WP_Error( 'mmi_ai_provider', __( 'Unknown AI provider.', 'mmi-shared' ) );
			}
			$response = wp_remote_get( $p['base'] . 'models' . ( $slug === 'anthropic' ? '?limit=100' : '' ), array( 'timeout' => 15, 'headers' => self::headers( $slug, $key ) ) );
			$body     = self::parse( $slug, $response );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$ids = array();
			foreach ( (array) ( $body['data'] ?? $body['models'] ?? array() ) as $row ) {
				$id = (string) ( is_array( $row ) ? ( $row['id'] ?? $row['name'] ?? '' ) : $row );
				$id = (string) preg_replace( '#^models/#', '', $id ); // Gemini prefixes ids with "models/"
				if ( $id !== '' && ! preg_match( self::NOT_TEXT, $id ) ) {
					$ids[] = $id;
				}
			}
			return array_values( array_unique( $ids ) );
		}

		/** The model to preselect among those a key can use. */
		public static function preferred_model( string $slug, array $models ): string {
			foreach ( self::PROVIDERS[ $slug ]['prefer'] ?? array() as $pattern ) {
				foreach ( $models as $id ) {
					if ( preg_match( $pattern, $id ) ) {
						return $id;
					}
				}
			}
			return (string) ( $models[0] ?? '' );
		}

		private static function headers( string $slug, string $key ): array {
			if ( self::PROVIDERS[ $slug ]['format'] === 'anthropic' ) {
				return array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
			}
			return array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' );
		}

		/**
		 * The request body for one completion.
		 *
		 * @param array|null $image { mime, data (base64) }
		 */
		public static function build_body( string $slug, string $model, string $system, string $user, ?array $image ): array {
			$p = self::PROVIDERS[ $slug ];
			if ( $p['format'] === 'anthropic' ) {
				$content = array();
				if ( $image ) {
					$content[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data'] ) );
				}
				$content[] = array( 'type' => 'text', 'text' => $user );
				return array(
					'model'      => $model,
					'max_tokens' => self::MAX_TOKENS,
					'system'     => $system,
					'messages'   => array( array( 'role' => 'user', 'content' => $content ) ),
				);
			}

			$content = $user;
			if ( $image ) {
				$url     = 'data:' . $image['mime'] . ';base64,' . $image['data'];
				$content = array(
					array( 'type' => 'text', 'text' => $user ),
					array( 'type' => 'image_url', 'image_url' => ! empty( $p['image_string'] ) ? $url : array( 'url' => $url, 'detail' => 'low' ) ),
				);
			}
			$body = array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $content ),
				),
			);
			// OpenAI's newer models refuse max_tokens; the others don't know max_completion_tokens.
			$body[ $slug === 'openai' ? 'max_completion_tokens' : 'max_tokens' ] = self::MAX_TOKENS;
			return $body;
		}

		/**
		 * Decoded JSON body of a provider response, or WP_Error saying what to do.
		 *
		 * @param array|WP_Error $response wp_remote_* result.
		 * @return array|WP_Error
		 */
		private static function parse( string $slug, $response ) {
			$name = self::name( $slug );
			if ( is_wp_error( $response ) ) {
				/* translators: 1: provider, 2: error */
				return new WP_Error( 'mmi_ai_http', sprintf( __( '%1$s didn\'t answer: %2$s', 'mmi-shared' ), $name, $response->get_error_message() ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$body = is_array( $body ) ? $body : array();
			if ( $code === 200 ) {
				return $body;
			}

			$err     = $body['error'] ?? ( $body[0]['error'] ?? array() ); // Gemini can wrap its error in a list
			$message = is_array( $err ) ? (string) ( $err['message'] ?? '' ) : (string) $err;
			$type    = is_array( $err ) ? strtolower( (string) ( $err['code'] ?? $err['type'] ?? $err['status'] ?? '' ) ) : '';
			$message = $message !== '' ? $message : (string) ( $body['message'] ?? '' );
			if ( $code === 429 && class_exists( 'MMI_API_Throttler' ) ) {
				MMI_API_Throttler::penalize( 'ai_' . $slug, (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
			}
			self::log( 'warn', sprintf( '[AI] %s HTTP %d %s', $slug, $code, $type ) );

			if ( $code === 401 || $code === 403 || strpos( $type, 'authentication' ) !== false || stripos( $message, 'api key' ) !== false && $code === 400 ) {
				/* translators: %s: provider */
				$text = sprintf( __( '%s refused the API key. Check that it was copied whole and hasn\'t been deleted, then save it again.', 'mmi-shared' ), $name );
				return new WP_Error( 'mmi_ai_key', $text, array( 'stop' => true ) );
			}
			if ( $code === 402 || strpos( $type, 'insufficient_quota' ) !== false || stripos( $message, 'credit' ) !== false || stripos( $message, 'billing' ) !== false || stripos( $message, 'balance' ) !== false ) {
				/* translators: %s: provider */
				$text = sprintf( __( 'The %s account has no credit or billing set up for the API. Add it in the provider\'s billing settings; nothing was written.', 'mmi-shared' ), $name );
				return new WP_Error( 'mmi_ai_billing', $text, array( 'stop' => true ) );
			}
			if ( $code === 404 || strpos( $type, 'model_not_found' ) !== false ) {
				/* translators: 1: provider, 2: where AI is set up */
				return new WP_Error( 'mmi_ai_model', sprintf( __( '%1$s doesn\'t offer the chosen model to this key. Open %2$s and pick a model from the list.', 'mmi-shared' ), $name, self::where() ), array( 'stop' => true ) );
			}
			if ( $code === 429 || $code === 529 || $code >= 500 ) {
				/* translators: %s: provider */
				return new WP_Error( 'mmi_ai_busy', sprintf( __( '%s is busy or limiting requests right now. Try again in a minute.', 'mmi-shared' ), $name ), array( 'retry' => true ) );
			}
			/* translators: 1: provider, 2: HTTP status, 3: provider's message */
			return new WP_Error( 'mmi_ai_api', sprintf( __( '%1$s answered HTTP %2$d: %3$s', 'mmi-shared' ), $name, $code, wp_strip_all_tags( mb_substr( $message, 0, 300 ) ) ) );
		}

		/** The reply text and token counts from a decoded completion. */
		public static function read_reply( string $slug, array $body ): array {
			if ( self::PROVIDERS[ $slug ]['format'] === 'anthropic' ) {
				$text = '';
				foreach ( (array) ( $body['content'] ?? array() ) as $part ) {
					if ( ( $part['type'] ?? '' ) === 'text' ) {
						$text .= (string) $part['text'];
					}
				}
				return array( 'text' => $text, 'input' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output' => (int) ( $body['usage']['output_tokens'] ?? 0 ) );
			}
			$content = $body['choices'][0]['message']['content'] ?? '';
			if ( is_array( $content ) ) { // some providers answer in parts
				$content = implode( '', array_map( static fn( $p ) => is_array( $p ) ? (string) ( $p['text'] ?? '' ) : (string) $p, $content ) );
			}
			return array( 'text' => (string) $content, 'input' => (int) ( $body['usage']['prompt_tokens'] ?? 0 ), 'output' => (int) ( $body['usage']['completion_tokens'] ?? 0 ) );
		}

		/**
		 * One completion with the chosen provider, counted against the daily cap.
		 *
		 * @param array|null $image  { mime, data (base64) }
		 * @param array|null $schema The answer's JSON schema: a plan must have one;
		 *                           API providers are asked in the prompt.
		 * @return string|WP_Error The reply text (the answer as JSON on a plan).
		 */
		public static function complete( string $system, string $user, ?array $image = null, string $purpose = '', ?array $schema = null ) {
			$slug = self::provider();
			if ( ! self::ready() ) {
				$off = self::on_subscription()
					/* translators: %s: where AI is set up */
					? sprintf( __( 'AI writing isn\'t set up: Claude Code on this server isn\'t signed in with a Claude Pro or Max plan. Open %s for the steps.', 'mmi-shared' ), self::where() )
					/* translators: %s: where AI is set up */
					: sprintf( __( 'AI writing isn\'t set up: choose a provider, save its API key and pick a model on %s.', 'mmi-shared' ), self::where() );
				return new WP_Error( 'mmi_ai_off', $off, array( 'stop' => true ) );
			}
			if ( self::remaining_today() <= 0 ) {
				/* translators: 1: daily request cap, 2: where AI is set up */
				return new WP_Error( 'mmi_ai_cap', sprintf( __( 'Today\'s limit of %1$d AI requests is reached. It resets at midnight, or raise it on %2$s.', 'mmi-shared' ), self::daily_cap(), self::where() ), array( 'stop' => true ) );
			}
			if ( self::on_subscription() ) {
				return self::complete_subscription( $system, $user, $image, $purpose, $schema );
			}
			$p     = self::PROVIDERS[ $slug ];
			$model = self::model( $slug );
			$url   = $p['base'] . ( $p['format'] === 'anthropic' ? 'messages' : 'chat/completions' );
			$start = microtime( true );

			if ( class_exists( 'MMI_API_Throttler' ) ) {
				MMI_API_Throttler::throttle( 'ai_' . $slug, self::THROTTLE );
			}
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => self::headers( $slug, self::key( $slug ) ),
					'body'    => wp_json_encode( self::build_body( $slug, $model, $system, $user, $image ) ),
				)
			);
			$body = self::parse( $slug, $response );
			if ( is_wp_error( $body ) ) {
				// Only a request the provider took counts: one refused before it reached a model isn't billed.
				$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 500 ) {
					self::record_usage( false, 0, 0 );
				}
				return $body;
			}
			$reply = self::read_reply( $slug, $body );
			self::record_usage( true, $reply['input'], $reply['output'] );
			self::log( 'info', sprintf( '[AI] %s %s %s: %d in, %d out tokens, %d ms', $slug, $model, $purpose, $reply['input'], $reply['output'], (int) ( ( microtime( true ) - $start ) * 1000 ) ) );

			if ( trim( $reply['text'] ) === '' ) {
				/* translators: %s: provider */
				return new WP_Error( 'mmi_ai_empty', sprintf( __( '%s sent back an empty answer. Try again, or choose another model.', 'mmi-shared' ), self::name( $slug ) ) );
			}
			return $reply['text'];
		}

		/** complete() on the Claude plan: the answer comes back as JSON matching $schema. */
		private static function complete_subscription( string $system, string $user, ?array $image, string $purpose, ?array $schema ) {
			$schema = $schema ?? self::schema( array( 'text' ) );
			$start  = microtime( true );
			set_time_limit( MMI_AI_Claude_Code::TIMEOUT + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions -- the CLI may take most of a minute
			$reply = MMI_AI_Claude_Code::complete( $system, $user, $schema, $image );
			if ( is_wp_error( $reply ) ) {
				self::log( 'warn', sprintf( '[AI] %s plan %s: %s', self::provider(), $purpose, $reply->get_error_message() ) );
				return $reply;
			}
			self::record_usage( true, $reply['input'], $reply['output'] );
			self::log( 'info', sprintf( '[AI] %s plan %s %s: %d in, %d out tokens, %d ms', self::provider(), MMI_AI_Claude_Code::model(), $purpose, $reply['input'], $reply['output'], (int) ( ( microtime( true ) - $start ) * 1000 ) ) );
			return isset( $reply['answer']['text'] ) && count( $reply['answer'] ) === 1 ? (string) $reply['answer']['text'] : (string) wp_json_encode( $reply['answer'] );
		}

		/**
		 * Whether a call can go now, and why not. Checks the provider is set up,
		 * today's cap, a plan paused for extra usage, and whatever the site adds
		 * (filter 'mmi_ai_preflight', e.g. a live check of the plan's usage).
		 * For batch callers that want to stop before starting work.
		 *
		 * @return array{ok:bool, reason:string}
		 */
		public static function preflight(): array {
			if ( ! self::ready() ) {
				/* translators: %s: where AI is set up */
				return array( 'ok' => false, 'reason' => sprintf( __( 'AI isn\'t set up: choose a provider on %s.', 'mmi-shared' ), self::where() ) );
			}
			if ( self::remaining_today() <= 0 ) {
				/* translators: %d: daily request cap */
				return array( 'ok' => false, 'reason' => sprintf( __( 'Today\'s limit of %d AI requests is reached.', 'mmi-shared' ), self::daily_cap() ) );
			}
			if ( self::on_subscription() && MMI_AI_Claude_Code::paused_at() ) {
				return array( 'ok' => false, 'reason' => __( 'Writing on the plan is paused for a day: a call was billed as extra usage.', 'mmi-shared' ) );
			}
			$r = apply_filters( 'mmi_ai_preflight', array( 'ok' => true, 'reason' => '' ) );
			return array( 'ok' => ! empty( $r['ok'] ), 'reason' => (string) ( $r['reason'] ?? '' ) );
		}

		/**
		 * One structured answer for callers that need more than complete(): several
		 * pictures in one question, a bigger model, more time, thinking (1.49.0).
		 * Counted against the same daily cap and usage as complete().
		 *
		 * @param array $args {
		 *     system     string  The instructions.
		 *     content    string|array  The question: text, or Messages API content blocks
		 *                       ( { type: text, text } and { type: image, source: { type: base64, media_type, data } } ).
		 *     schema     array   JSON schema of the answer (an object). Required.
		 *     purpose    string  Who asks, for logs and a site's call log.
		 *     model      string  On a plan: the model to use (alias or id). With an API key the AI settings' model is used.
		 *     timeout    int     Seconds. On a plan, longer than a web request allows only from WP-CLI.
		 *     thinking   bool    On a plan: leave thinking on (default off).
		 *     max_tokens int     With an API key: the answer's length limit (default MAX_TOKENS).
		 * }
		 * @return array|WP_Error { answer: array, input: int, output: int }
		 */
		public static function ask( array $args ) {
			$system  = (string) ( $args['system'] ?? '' );
			$schema  = (array) ( $args['schema'] ?? array() );
			$purpose = (string) ( $args['purpose'] ?? '' );
			$content = $args['content'] ?? '';
			$content = is_array( $content ) ? array_values( $content ) : array( array( 'type' => 'text', 'text' => (string) $content ) );
			if ( ! $schema ) {
				return new WP_Error( 'mmi_ai_schema', __( 'No answer shape was given.', 'mmi-shared' ) );
			}
			$pre = self::preflight();
			if ( ! $pre['ok'] ) {
				return new WP_Error( 'mmi_ai_off', $pre['reason'], array( 'stop' => true ) );
			}
			$slug    = self::provider();
			$timeout = max( 0, (int) ( $args['timeout'] ?? 0 ) );
			$start   = microtime( true );

			if ( self::on_subscription() ) {
				if ( $timeout > 0 ) {
					set_time_limit( $timeout + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions -- the caller asked for the time
				}
				$r = MMI_AI_Claude_Code::complete_content( $system, $content, $schema, (string) ( $args['model'] ?? '' ), $timeout, ! empty( $args['thinking'] ), $purpose );
				if ( is_wp_error( $r ) ) {
					self::log( 'warn', sprintf( '[AI] %s plan %s: %s', $slug, $purpose, $r->get_error_message() ) );
					return $r;
				}
				self::record_usage( true, $r['input'], $r['output'] );
				self::log( 'info', sprintf( '[AI] %s plan %s %s: %d in, %d out tokens, %d ms', $slug, (string) ( $args['model'] ?? '' ), $purpose, $r['input'], $r['output'], (int) ( ( microtime( true ) - $start ) * 1000 ) ) );
				return $r;
			}

			$p      = self::PROVIDERS[ $slug ];
			$images = array_filter( $content, static fn( $b ) => ( $b['type'] ?? '' ) === 'image' );
			if ( $images && empty( $p['vision'] ) ) {
				/* translators: %s: provider */
				return new WP_Error( 'mmi_ai_no_vision', sprintf( __( '%s can\'t see pictures, and this question needs them. Choose another provider.', 'mmi-shared' ), self::name( $slug ) ), array( 'stop' => true ) );
			}
			$model  = self::model( $slug );
			$system = $system . "\n\nAnswer with one JSON object only, no other text, matching this JSON schema:\n" . wp_json_encode( $schema );
			if ( $p['format'] === 'anthropic' ) {
				$body = array( 'model' => $model, 'max_tokens' => (int) ( $args['max_tokens'] ?? self::MAX_TOKENS ), 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $content ) ) );
			} else {
				$parts = array();
				foreach ( $content as $b ) {
					if ( ( $b['type'] ?? '' ) === 'image' ) {
						$url     = 'data:' . $b['source']['media_type'] . ';base64,' . $b['source']['data'];
						$parts[] = array( 'type' => 'image_url', 'image_url' => ! empty( $p['image_string'] ) ? $url : array( 'url' => $url, 'detail' => 'low' ) );
					} else {
						$parts[] = array( 'type' => 'text', 'text' => (string) ( $b['text'] ?? '' ) );
					}
				}
				$body = array( 'model' => $model, 'messages' => array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $parts ) ) );
				$body[ $slug === 'openai' ? 'max_completion_tokens' : 'max_tokens' ] = (int) ( $args['max_tokens'] ?? self::MAX_TOKENS );
			}
			if ( class_exists( 'MMI_API_Throttler' ) ) {
				MMI_API_Throttler::throttle( 'ai_' . $slug, self::THROTTLE );
			}
			$response = wp_remote_post( $p['base'] . ( $p['format'] === 'anthropic' ? 'messages' : 'chat/completions' ), array( 'timeout' => max( self::TIMEOUT, $timeout ), 'headers' => self::headers( $slug, self::key( $slug ) ), 'body' => wp_json_encode( $body ) ) );
			$decoded  = self::parse( $slug, $response );
			if ( is_wp_error( $decoded ) ) {
				if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
					self::record_usage( false, 0, 0 );
				}
				return $decoded;
			}
			$reply = self::read_reply( $slug, $decoded );
			self::record_usage( true, $reply['input'], $reply['output'] );
			self::log( 'info', sprintf( '[AI] %s %s %s: %d in, %d out tokens, %d ms', $slug, $model, $purpose, $reply['input'], $reply['output'], (int) ( ( microtime( true ) - $start ) * 1000 ) ) );
			$answer = self::json_from( $reply['text'] );
			if ( ! $answer ) {
				/* translators: %s: provider */
				return new WP_Error( 'mmi_ai_format', sprintf( __( '%s\'s answer couldn\'t be read. Try again, or choose another model.', 'mmi-shared' ), self::name( $slug ) ) );
			}
			return array( 'answer' => $answer, 'input' => $reply['input'], 'output' => $reply['output'] );
		}

		/** A JSON schema for an answer of plain string fields, all required. */
		public static function schema( array $fields ): array {
			return array(
				'type'                 => 'object',
				'properties'           => array_fill_keys( $fields, array( 'type' => 'string' ) ),
				'required'             => array_values( $fields ),
				'additionalProperties' => false,
			);
		}

		/** The first JSON object in a reply (models sometimes wrap it in ``` fences or a sentence). */
		public static function json_from( string $reply ): array {
			$start = strpos( $reply, '{' );
			$end   = strrpos( $reply, '}' );
			if ( $start === false || $end === false || $end < $start ) {
				return array();
			}
			$json = json_decode( substr( $reply, $start, $end - $start + 1 ), true );
			return is_array( $json ) ? $json : array();
		}

		/** One plain line: no surrounding quotes, tags, line breaks or token braces. */
		public static function clean_line( string $text ): string {
			$text = wp_strip_all_tags( $text );
			$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
			$text = trim( $text, " \t\"'“”‘’`" );
			return str_replace( array( '{', '}' ), array( '(', ')' ), $text ); // braces would read as title tokens
		}

		public static function log( string $level, string $message ): void {
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::$level( $message, array(), self::LOG_CATEGORY, 'MMI_AI' );
			}
		}
	}

endif;

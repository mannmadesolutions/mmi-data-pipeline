<?php
/**
 * MMI_Notifications — one store, one dashboard and one daily email for
 * everything the suite wants a site owner to know.
 *
 * Before this (lib 1.53.0, 2026-11-26) every plugin mailed the site admin on
 * its own schedule: hourly cron alarms, a mail per release, per-run
 * reports. A site with several MMI plugins got a dozen-plus mails a day.
 * Now a plugin calls notify() instead of wp_mail() for anything addressed to
 * the site owner, and the owner gets:
 *
 *   - the dashboard (render_dashboard()), always current;
 *   - one summary email a day per channel (send_digest(), the same sections
 *     as the dashboard), sent only when something new happened or a
 *     critical problem is still open;
 *   - an immediate email only for an `urgent` notice, at most once a day per
 *     problem and URGENT_DAILY_CAP a day per channel. Past the cap it waits
 *     for the summary like everything else.
 *
 * Mail to customers, suppliers and list members, and test mails an admin
 * clicked to send, are not notifications: they keep using wp_mail().
 *
 * Channels: 'suite' (MannMade → Dashboard, every site) is built in. A plugin
 * adds one through the 'mmi_notifications_channels' filter (mmi-admin adds
 * 'admin' for MannMade's own release, licensing and server mail). A notice
 * for a channel nobody registered on this site lands in 'suite'.
 *
 * Problems vs. events: a 'critical' or 'warning' notice is a problem. It
 * stays under "Needs attention" until its plugin calls resolve() with the
 * same key, or someone dismisses it. Raising the same key again while it is
 * open updates it and counts the repeat instead of adding a row. An 'info'
 * notice is an event (a release published, a report ready): it is born
 * resolved and only shows in the activity list.
 *
 * Storage: {prefix}mmi_notifications, created on first use. Every read is
 * indexed and LIMITed, so the dashboard's render path stays cheap (AGENTS.md
 * Server Load: page render).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Notifications {

	const DIGEST_HOOK        = 'mmi_notifications_digest';
	const DIGEST_HOUR        = 7;   // Site-local hour the summary goes out.
	const URGENT_DAILY_CAP   = 5;   // Immediate emails per channel per 24 h.
	const KEEP_DAYS          = 60;  // Resolved rows older than this are pruned.
	const ACTIVITY_DAYS      = 7;
	const LIST_LIMIT         = 50;
	const LAST_DIGEST_PREFIX = 'mmi_notifications_last_digest_';
	const DISMISS_ACTION     = 'mmi_notification_dismiss';
	const SEND_ACTION        = 'mmi_notifications_send_now';
	const PREVIEW_ACTION     = 'mmi_notifications_preview';

	const SEVERITIES = array( 'critical', 'warning', 'info' );

	private static $table_ensured = false;

	/* ── Writing ─────────────────────────────────────────────────────────── */

	/**
	 * Record a notice for the site owner.
	 *
	 * @param array $args {
	 *   @type string $source   Plugin slug ('mmi-reverb-integration'). Required.
	 *   @type string $title    One line. Required.
	 *   @type string $body     Plain text; blank lines separate paragraphs.
	 *   @type string $severity critical|warning|info (default info).
	 *   @type string $key      Stable id of the problem or event, unique within
	 *                          the source. Omit for a one-off event.
	 *   @type string $url      Where to act on it.
	 *   @type string $channel  'suite' (default) or a registered channel.
	 *   @type bool   $urgent   Also email now (critical problems only).
	 * }
	 * @return int Row id, 0 when nothing could be stored.
	 */
	public static function notify( array $args ): int {
		global $wpdb;

		$source = sanitize_key( (string) ( $args['source'] ?? '' ) );
		$title  = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		if ( $source === '' || $title === '' ) {
			return 0;
		}
		$severity = in_array( $args['severity'] ?? '', self::SEVERITIES, true ) ? $args['severity'] : 'info';
		$body     = sanitize_textarea_field( (string) ( $args['body'] ?? '' ) );
		$url      = esc_url_raw( (string) ( $args['url'] ?? '' ) );
		$channel  = self::resolve_channel( (string) ( $args['channel'] ?? 'suite' ) );
		$key      = (string) ( $args['key'] ?? '' );
		$nkey     = substr( $source . ':' . ( $key !== '' ? $key : md5( $title . "\n" . $body . "\n" . microtime() ) ), 0, 191 );
		$now      = current_time( 'mysql', true );
		$table    = self::table();

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, resolved_at FROM {$table} WHERE channel = %s AND nkey = %s", $channel, $nkey ),
			ARRAY_A
		);

		$fields = array(
			'severity'  => $severity,
			'title'     => $title,
			'body'      => $body,
			'url'       => $url,
			'last_seen' => $now,
		);

		if ( $existing ) {
			$id       = (int) $existing['id'];
			$reopened = $existing['resolved_at'] !== null && $severity !== 'info';
			if ( $reopened ) {
				// A problem that came back is a new episode: new on the dashboard
				// and in the next summary again.
				$fields += array( 'first_seen' => $now, 'resolved_at' => null, 'emailed_at' => null, 'dismissed' => 0, 'occurrences' => 1 );
			} elseif ( $severity === 'info' ) {
				$fields += array( 'resolved_at' => $now, 'emailed_at' => null );
			}
			// A repeat of an open problem (dismissed or not) only updates the
			// text and counts it; it is not new again.
			$wpdb->update( $table, $fields, array( 'id' => $id ) );
			if ( ! $reopened ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET occurrences = occurrences + 1 WHERE id = %d", $id ) );
			}
		} else {
			$fields += array(
				'channel'     => $channel,
				'source'      => $source,
				'nkey'        => $nkey,
				'first_seen'  => $now,
				'resolved_at' => $severity === 'info' ? $now : null,
				'occurrences' => 1,
			);
			if ( ! $wpdb->insert( $table, $fields ) ) {
				return 0;
			}
			$id = (int) $wpdb->insert_id;
		}

		if ( ! empty( $args['urgent'] ) && $severity === 'critical' ) {
			self::maybe_send_urgent( $id, $channel );
		}
		return $id;
	}

	/**
	 * The problem a plugin raised under $key is over. Safe to call every run:
	 * it only touches an open row.
	 */
	public static function resolve( string $source, string $key, string $channel = 'suite' ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET resolved_at = %s WHERE channel = %s AND nkey = %s AND resolved_at IS NULL",
				current_time( 'mysql', true ),
				self::resolve_channel( $channel ),
				substr( sanitize_key( $source ) . ':' . $key, 0, 191 )
			)
		);
	}

	/**
	 * Hide an open problem from "Needs attention" and the emails while it
	 * goes on. It comes back only if its plugin resolves it and it happens
	 * again later.
	 */
	public static function dismiss( int $id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'dismissed' => 1, 'emailed_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	/* ── Reading ─────────────────────────────────────────────────────────── */

	/** Open problems, critical first, then newest. */
	public static function open_problems( string $channel ): array {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel = %s AND resolved_at IS NULL AND dismissed = 0
				 ORDER BY FIELD(severity, 'critical', 'warning', 'info'), last_seen DESC LIMIT %d",
				$channel,
				self::LIST_LIMIT
			),
			ARRAY_A
		);
	}

	/** Events and solved problems since $since (UTC mysql), newest first. */
	public static function activity( string $channel, string $since ): array {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel = %s AND resolved_at IS NOT NULL AND resolved_at >= %s
				 ORDER BY resolved_at DESC LIMIT %d",
				$channel,
				$since,
				self::LIST_LIMIT
			),
			ARRAY_A
		);
	}

	/**
	 * Everything both the dashboard and the email show, gathered once.
	 *
	 * @return array{problems: array, alerts: array, activity: array, counts: array, new_ids: int[]}
	 */
	public static function snapshot( string $channel, string $since = '' ): array {
		$since    = $since !== '' ? $since : gmdate( 'Y-m-d H:i:s', time() - self::ACTIVITY_DAYS * DAY_IN_SECONDS );
		$problems = self::open_problems( $channel );
		$activity = self::activity( $channel, $since );
		$alerts   = self::live_alerts( $channel );

		$counts  = array( 'critical' => 0, 'warning' => 0, 'new' => 0, 'activity' => count( $activity ) );
		$new_ids = array();
		$day_ago = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( $problems as $row ) {
			$counts[ $row['severity'] ] = ( $counts[ $row['severity'] ] ?? 0 ) + 1;
			if ( $row['first_seen'] >= $day_ago ) {
				$counts['new']++;
			}
			if ( $row['emailed_at'] === null ) {
				$new_ids[] = (int) $row['id'];
			}
		}
		foreach ( $alerts as $alert ) {
			$counts[ $alert['severity'] ]++;
		}
		foreach ( $activity as $row ) {
			if ( $row['emailed_at'] === null ) {
				$new_ids[] = (int) $row['id'];
			}
		}
		return compact( 'problems', 'alerts', 'activity', 'counts', 'new_ids' );
	}

	/**
	 * Standing conditions plugins report through 'mmi_dashboard_alerts'
	 * (stored facts only, per that filter's contract). Shown on the suite
	 * channel, which is where that filter has always been displayed.
	 */
	private static function live_alerts( string $channel ): array {
		if ( $channel !== 'suite' ) {
			return array();
		}
		$alerts = array();
		foreach ( (array) apply_filters( 'mmi_dashboard_alerts', array() ) as $alert ) {
			if ( ! isset( $alert['title'], $alert['description'] ) ) {
				continue;
			}
			$alerts[] = array(
				'severity'  => ( $alert['severity'] ?? '' ) === 'critical' ? 'critical' : 'warning',
				'title'     => (string) $alert['title'],
				'body'      => (string) $alert['description'],
				'url'       => (string) ( $alert['url'] ?? '' ),
				'page_slug' => (string) ( $alert['page_slug'] ?? '' ),
			);
		}
		return $alerts;
	}

	/* ── Channels ────────────────────────────────────────────────────────── */

	/** @return array<string, array{label: string, url: string}> */
	public static function channels(): array {
		$channels = array(
			'suite' => array(
				'label' => __( 'MannMade', 'mmi-shared' ),
				'url'   => admin_url( 'admin.php?page=mmi-dashboard' ),
			),
		);
		return (array) apply_filters( 'mmi_notifications_channels', $channels );
	}

	private static function resolve_channel( string $channel ): string {
		return isset( self::channels()[ $channel ] ) ? $channel : 'suite';
	}

	/** @return string[] */
	public static function recipients( string $channel ): array {
		$to = (array) apply_filters( 'mmi_notifications_recipients', array( get_option( 'admin_email' ) ), $channel );
		return array_values( array_filter( array_map( 'sanitize_email', $to ) ) );
	}

	/** Plugin label for a source slug ('mmi-seo' → 'SEO'). */
	public static function source_label( string $source ): string {
		if ( class_exists( 'MMI_Feature_Gate' ) ) {
			$plugins = MMI_Feature_Gate::plugins();
			if ( isset( $plugins[ $source ]['short'] ) ) {
				return (string) $plugins[ $source ]['short'];
			}
		}
		$labels = array(
			'mmi-admin'  => 'MMI Admin',
			'server'     => 'Server',
			'mmi-audit'  => 'Code audit',
			'mmi-shared' => 'MannMade',
		);
		return $labels[ $source ] ?? ucwords( str_replace( array( 'mmi-', '-' ), array( '', ' ' ), $source ) );
	}

	/* ── Email ───────────────────────────────────────────────────────────── */

	/**
	 * Daily summary for one channel. Sends when something is new since the
	 * last summary or a critical problem is still open; a quiet day sends
	 * nothing. $force sends regardless (the dashboard's "Send now").
	 */
	public static function send_digest( string $channel, bool $force = false ): bool {
		$last = (string) MMI_Settings::get( self::LAST_DIGEST_PREFIX . $channel, '' );
		$snap = self::snapshot( $channel, $last !== '' ? $last : gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$has_new      = ! empty( $snap['new_ids'] );
		$has_critical = $snap['counts']['critical'] > 0;
		if ( ! $force && ! $has_new && ! $has_critical ) {
			return false;
		}

		$label   = self::channels()[ $channel ]['label'] ?? 'MannMade';
		$subject = self::digest_subject( $label, $snap['counts'] );
		$sent    = self::mail( $channel, $subject, self::render_email( $channel, $snap, false ) );

		if ( $sent ) {
			self::mark_emailed( $snap['new_ids'] );
			MMI_Settings::set( self::LAST_DIGEST_PREFIX . $channel, current_time( 'mysql', true ) );
		}
		if ( class_exists( 'MMI_Logger' ) ) {
			MMI_Logger::info(
				'Daily summary ' . ( $sent ? 'sent' : 'failed' ),
				array( 'channel' => $channel, 'critical' => $snap['counts']['critical'], 'warning' => $snap['counts']['warning'], 'items' => count( $snap['new_ids'] ) ),
				'general',
				'MMI_Notifications'
			);
		}
		return $sent;
	}

	private static function digest_subject( string $label, array $counts ): string {
		$parts = array();
		if ( $counts['critical'] ) {
			/* translators: %d: number of critical problems */
			$parts[] = sprintf( _n( '%d critical', '%d critical', $counts['critical'], 'mmi-shared' ), $counts['critical'] );
		}
		if ( $counts['warning'] ) {
			/* translators: %d: number of warnings */
			$parts[] = sprintf( _n( '%d warning', '%d warnings', $counts['warning'], 'mmi-shared' ), $counts['warning'] );
		}
		if ( ! $parts ) {
			$parts[] = __( 'all clear', 'mmi-shared' );
		}
		/* translators: 1: dashboard name, 2: counts such as "1 critical, 3 warnings" */
		return sprintf( __( '[%1$s] Daily summary: %2$s', 'mmi-shared' ), $label, implode( ', ', $parts ) );
	}

	/**
	 * Immediate email for one urgent problem, rendered as the same summary
	 * with that problem on top so the reader sees what else is open.
	 */
	private static function maybe_send_urgent( int $id, string $channel ): void {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row || (int) $row['dismissed'] === 1 ) {
			return;
		}
		$day_ago = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		if ( $row['urgent_sent_at'] !== null && $row['urgent_sent_at'] >= $day_ago ) {
			return;
		}
		$sent_today = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel = %s AND urgent_sent_at >= %s", $channel, $day_ago )
		);
		if ( $sent_today >= self::URGENT_DAILY_CAP ) {
			return;
		}

		$snap  = self::snapshot( $channel );
		$label = self::channels()[ $channel ]['label'] ?? 'MannMade';
		/* translators: 1: dashboard name, 2: problem title */
		$subject = sprintf( __( '[%1$s] Urgent: %2$s', 'mmi-shared' ), $label, $row['title'] );
		if ( self::mail( $channel, $subject, self::render_email( $channel, $snap, true, $row ) ) ) {
			$now = current_time( 'mysql', true );
			$wpdb->update( $table, array( 'urgent_sent_at' => $now, 'emailed_at' => $now ), array( 'id' => $id ) );
		}
	}

	private static function mail( string $channel, string $subject, string $html ): bool {
		$to = self::recipients( $channel );
		if ( ! $to ) {
			return false;
		}
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/** @param int[] $ids */
	private static function mark_emailed( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET emailed_at = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- one %d per id.
				array_merge( array( current_time( 'mysql', true ) ), $ids )
			)
		);
	}

	/**
	 * The summary email: the dashboard's sections in email markup.
	 *
	 * @param array|null $lead An urgent row to put first.
	 */
	public static function render_email( string $channel, array $snap, bool $urgent = false, ?array $lead = null ): string {
		$fmt   = static function ( array $row ): array {
			return array(
				'severity' => $row['severity'],
				'source'   => isset( $row['source'] ) ? self::source_label( $row['source'] ) : '',
				'title'    => $row['title'],
				'body'     => $row['body'],
				'url'      => $row['url'],
				'meta'     => isset( $row['first_seen'] ) ? self::row_meta( $row ) : '',
				'is_new'   => isset( $row['emailed_at'] ) ? $row['emailed_at'] === null : false,
			);
		};
		$problems = array();
		if ( $lead ) {
			$problems[] = $fmt( $lead );
		}
		foreach ( $snap['problems'] as $row ) {
			if ( ! $lead || (int) $row['id'] !== (int) $lead['id'] ) {
				$problems[] = $fmt( $row );
			}
		}
		foreach ( $snap['alerts'] as $alert ) {
			$problems[] = $fmt( $alert );
		}
		$activity = array();
		foreach ( $snap['activity'] as $row ) {
			$activity[] = array(
				'when'   => self::local_time( $row['resolved_at'], 'M j, g:i a' ),
				'source' => self::source_label( $row['source'] ),
				'title'  => $row['title'],
				'body'   => $row['body'],
				'url'    => $row['url'],
				'solved' => $row['severity'] !== 'info',
			);
		}

		$label = self::channels()[ $channel ]['label'] ?? 'MannMade';
		return MMI_Email_Templates::render_summary(
			array(
				'headline'      => $urgent
					/* translators: %s: dashboard name */
					? sprintf( __( '%s: urgent problem', 'mmi-shared' ), $label )
					/* translators: %s: dashboard name */
					: sprintf( __( '%s daily summary', 'mmi-shared' ), $label ),
				'date'          => wp_date( 'l, F j, Y' ),
				'urgent'        => $urgent,
				'tiles'         => array(
					array( 'value' => $snap['counts']['critical'], 'label' => __( 'Critical', 'mmi-shared' ), 'tone' => $snap['counts']['critical'] ? 'danger' : '' ),
					array( 'value' => $snap['counts']['warning'], 'label' => __( 'Warnings', 'mmi-shared' ), 'tone' => $snap['counts']['warning'] ? 'warning' : '' ),
					array( 'value' => $snap['counts']['new'], 'label' => __( 'New today', 'mmi-shared' ), 'tone' => '' ),
					array( 'value' => $snap['counts']['activity'], 'label' => __( 'Updates', 'mmi-shared' ), 'tone' => '' ),
				),
				'problems'      => $problems,
				'activity'      => $activity,
				'dashboard_url' => self::channels()[ $channel ]['url'] ?? admin_url(),
			)
		);
	}

	/* ── Dashboard ───────────────────────────────────────────────────────── */

	/**
	 * The dashboard body for a channel: summary tiles, Needs attention,
	 * Recent activity. Same data and order as the email. Callers supply the
	 * page shell (header, tabs).
	 */
	public static function render_dashboard( string $channel ): void {
		$snap   = self::snapshot( $channel );
		$counts = $snap['counts'];
		$tiles  = array(
			array( 'critical', $counts['critical'], __( 'Critical', 'mmi-shared' ), $counts['critical'] ? 'error inline' : 'success' ),
			array( 'warning', $counts['warning'], __( 'Warnings', 'mmi-shared' ), $counts['warning'] ? 'warning' : 'success' ),
			array( 'new', $counts['new'], __( 'New today', 'mmi-shared' ), 'info' ),
			/* translators: %d: number of days */
			array( 'activity', $counts['activity'], sprintf( __( 'Updates, last %d days', 'mmi-shared' ), self::ACTIVITY_DAYS ), '' ),
		);

		echo '<div class="mmi-stats-grid mmi-notify-tiles">';
		foreach ( $tiles as $tile ) {
			printf(
				'<div class="mmi-stat-box %1$s"><div class="mmi-stat-label">%2$s</div><div class="mmi-stat-value">%3$s</div></div>',
				esc_attr( $tile[3] ),
				esc_html( $tile[2] ),
				esc_html( number_format_i18n( $tile[1] ) )
			);
		}
		echo '</div>';

		self::render_email_line( $channel );

		// Needs attention.
		echo '<div class="mmi-process-section mmi-notify-section">';
		self::section_header( 'dashicons-warning', __( 'Needs attention', 'mmi-shared' ), __( 'Problems that are still happening. Each one leaves this list by itself when its plugin sees it fixed.', 'mmi-shared' ) );
		echo '<div class="mmi-section-content">';
		if ( ! $snap['problems'] && ! $snap['alerts'] ) {
			echo '<p class="mmi-dashboard-empty">' . esc_html__( 'Nothing needs attention right now.', 'mmi-shared' ) . '</p>';
		} else {
			echo '<div class="mmi-dashboard-alerts">';
			foreach ( $snap['problems'] as $row ) {
				self::render_problem( $row, $channel );
			}
			foreach ( $snap['alerts'] as $alert ) {
				self::render_problem( $alert, $channel );
			}
			echo '</div>';
		}
		echo '</div></div>';

		// Recent activity.
		echo '<div class="mmi-process-section mmi-notify-section">';
		/* translators: %d: number of days */
		self::section_header( 'dashicons-backup', __( 'Recent activity', 'mmi-shared' ), sprintf( __( 'Reports, releases and solved problems from the last %d days.', 'mmi-shared' ), self::ACTIVITY_DAYS ) );
		echo '<div class="mmi-section-content">';
		if ( ! $snap['activity'] ) {
			echo '<p class="mmi-dashboard-empty">' . esc_html__( 'Nothing in the last week.', 'mmi-shared' ) . '</p>';
		} else {
			echo '<div class="mmi-table-scroll-wrapper"><table class="widefat striped mmi-notify-activity"><thead><tr>'
				. '<th>' . esc_html__( 'When', 'mmi-shared' ) . '</th>'
				. '<th>' . esc_html__( 'Plugin', 'mmi-shared' ) . '</th>'
				. '<th>' . esc_html__( 'What happened', 'mmi-shared' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $snap['activity'] as $row ) {
				$title = esc_html( $row['title'] );
				if ( $row['url'] ) {
					$title = sprintf( '<a href="%s">%s</a>', esc_url( $row['url'] ), $title );
				}
				$solved = $row['severity'] !== 'info'
					? ' <span class="mmi-badge success">' . esc_html__( 'Solved', 'mmi-shared' ) . '</span>'
					: '';
				printf(
					'<tr><td data-sort-value="%1$s">%2$s</td><td>%3$s</td><td><div class="mmi-notify-title">%4$s%5$s</div>%6$s</td></tr>',
					esc_attr( $row['resolved_at'] ),
					esc_html( self::local_time( $row['resolved_at'], 'M j, g:i a' ) ),
					esc_html( self::source_label( $row['source'] ) ),
					$title, // Escaped above.
					$solved, // Static markup.
					$row['body'] !== '' ? '<div class="mmi-notify-body">' . self::body_html( $row['body'] ) . '</div>' : ''
				);
			}
			echo '</tbody></table></div>';
		}
		echo '</div></div>';
	}

	private static function section_header( string $icon, string $title, string $description ): void {
		printf(
			'<div class="mmi-section-header"><h3 class="mmi-process-section-header"><span class="dashicons %1$s" aria-hidden="true"></span> %2$s</h3><p class="mmi-process-section-description">%3$s</p></div>',
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $description )
		);
	}

	private static function render_email_line( string $channel ): void {
		$to   = self::recipients( $channel );
		$last = (string) MMI_Settings::get( self::LAST_DIGEST_PREFIX . $channel, '' );
		$next = wp_next_scheduled( self::DIGEST_HOOK );

		$bits = array(
			sprintf(
				/* translators: 1: time of day, 2: email address(es) */
				__( 'The daily summary email goes out at %1$s to %2$s, only on days with something new or a critical problem still open. Urgent problems are emailed right away, at most once a day each.', 'mmi-shared' ),
				$next ? wp_date( get_option( 'time_format' ), $next ) : wp_date( get_option( 'time_format' ), mktime( self::DIGEST_HOUR, 0, 0 ) ),
				$to ? implode( ', ', $to ) : __( '(no address)', 'mmi-shared' )
			),
		);
		if ( $last !== '' ) {
			/* translators: %s: date and time */
			$bits[] = sprintf( __( 'Last sent %s.', 'mmi-shared' ), self::local_time( $last, 'M j, g:i a' ) );
		}

		$preview = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PREVIEW_ACTION . '&channel=' . rawurlencode( $channel ) ), self::PREVIEW_ACTION );
		$send    = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::SEND_ACTION . '&channel=' . rawurlencode( $channel ) ), self::SEND_ACTION );

		echo '<div class="mmi-notify-email-line"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span>' . esc_html( implode( ' ', $bits ) ) . '</span>';
		printf(
			'<span class="mmi-notify-email-actions"><a class="button" href="%1$s" target="_blank" rel="noopener">%2$s</a> <a class="button" href="%3$s">%4$s</a></span>',
			esc_url( $preview ),
			esc_html__( 'Preview email', 'mmi-shared' ),
			esc_url( $send ),
			esc_html__( 'Send it now', 'mmi-shared' )
		);
		echo '</div>';

		if ( isset( $_GET['mmi_summary_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display flag only.
			$ok = $_GET['mmi_summary_sent'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification
			printf(
				'<div class="notice inline %1$s"><p>%2$s</p></div>',
				$ok ? 'notice-success' : 'notice-error',
				esc_html( $ok ? __( 'Summary sent.', 'mmi-shared' ) : __( 'The summary could not be sent. Check that this site can send mail.', 'mmi-shared' ) )
			);
		}
	}

	private static function render_problem( array $row, string $channel ): void {
		$severity = $row['severity'] === 'critical' ? 'critical' : 'warning';
		$pill     = $severity === 'critical' ? __( 'Critical', 'mmi-shared' ) : __( 'Warning', 'mmi-shared' );
		$source   = isset( $row['source'] ) ? '<span class="mmi-notify-source">' . esc_html( self::source_label( $row['source'] ) ) . '</span>' : '';
		$is_new   = isset( $row['emailed_at'] ) && $row['emailed_at'] === null;
		$actions  = '';
		if ( ! empty( $row['url'] ) ) {
			$actions .= sprintf( '<a class="mmi-dashboard-alert-link" href="%s">%s &rarr;</a>', esc_url( $row['url'] ), esc_html__( 'Open', 'mmi-shared' ) );
		}
		if ( isset( $row['id'] ) ) {
			$dismiss  = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&id=' . (int) $row['id'] . '&channel=' . rawurlencode( $channel ) ),
				self::DISMISS_ACTION . '_' . (int) $row['id']
			);
			$actions .= sprintf( '<a class="mmi-notify-dismiss" href="%s">%s</a>', esc_url( $dismiss ), esc_html__( 'Dismiss', 'mmi-shared' ) );
		}
		printf(
			'<div class="mmi-dashboard-alert mmi-dashboard-alert--%1$s"><div class="mmi-notify-pills"><span class="mmi-dashboard-alert-pill mmi-dashboard-alert-pill--%1$s">%2$s</span>%3$s%4$s</div><div class="mmi-dashboard-alert-title">%5$s</div><div class="mmi-dashboard-alert-desc">%6$s</div>%7$s<div class="mmi-notify-actions">%8$s</div></div>',
			esc_attr( $severity ),
			esc_html( $pill ),
			$source, // Escaped above.
			$is_new ? '<span class="mmi-badge info">' . esc_html__( 'New', 'mmi-shared' ) . '</span>' : '',
			esc_html( $row['title'] ),
			self::body_html( (string) $row['body'] ),
			isset( $row['first_seen'] ) ? '<div class="mmi-notify-meta">' . esc_html( self::row_meta( $row ) ) . '</div>' : '',
			$actions // Escaped above.
		);
	}

	/** "First seen Nov 26, 7:02 am · last 2:02 pm · 8 times" */
	private static function row_meta( array $row ): string {
		/* translators: %s: date and time */
		$meta = sprintf( __( 'Since %s', 'mmi-shared' ), self::local_time( $row['first_seen'], 'M j, g:i a' ) );
		if ( (int) $row['occurrences'] > 1 ) {
			/* translators: 1: date and time, 2: count */
			$meta .= ' · ' . sprintf( __( 'last %1$s · %2$d times', 'mmi-shared' ), self::local_time( $row['last_seen'], 'M j, g:i a' ), (int) $row['occurrences'] );
		}
		return $meta;
	}

	/**
	 * Plain-text body → escaped paragraphs. A long body keeps its first
	 * paragraph in view and folds the rest into Details, so one verbose
	 * report doesn't push every other problem off the screen.
	 */
	public static function body_html( string $body ): string {
		if ( trim( $body ) === '' ) {
			return '';
		}
		$paras = array_map(
			static fn( $p ) => '<p>' . nl2br( esc_html( $p ) ) . '</p>',
			preg_split( "/\n\s*\n/", trim( $body ) )
		);
		if ( strlen( $body ) < 600 ) {
			return implode( '', $paras );
		}
		return array_shift( $paras ) . '<details class="mmi-notify-details"><summary>' . esc_html__( 'Details', 'mmi-shared' ) . '</summary>' . implode( '', $paras ) . '</details>';
	}

	private static function local_time( ?string $utc, string $format ): string {
		if ( ! $utc ) {
			return '';
		}
		return wp_date( $format, strtotime( $utc . ' UTC' ) );
	}

	/* ── Hooks ───────────────────────────────────────────────────────────── */

	/** Keeps the daily event scheduled at DIGEST_HOUR site time. */
	public static function ensure_schedule(): void {
		if ( wp_next_scheduled( self::DIGEST_HOOK ) ) {
			return;
		}
		$next = new DateTime( 'today ' . self::DIGEST_HOUR . ':00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::DIGEST_HOOK );
	}

	/** Daily event: one summary per channel, then prune old rows. */
	public static function run_digest(): void {
		if ( get_transient( 'mmi_notifications_digest_lock' ) ) {
			return;
		}
		set_transient( 'mmi_notifications_digest_lock', 1, 10 * MINUTE_IN_SECONDS );
		foreach ( array_keys( self::channels() ) as $channel ) {
			self::send_digest( $channel );
		}
		self::prune();
		delete_transient( 'mmi_notifications_digest_lock' );
	}

	private static function prune(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE resolved_at IS NOT NULL AND resolved_at < %s LIMIT 1000",
				gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS )
			)
		);
	}

	private static function can_manage(): bool {
		return current_user_can( (string) apply_filters( 'mmi_notifications_required_capability', 'manage_options' ) );
	}

	private static function request_channel(): string {
		return self::resolve_channel( sanitize_key( wp_unslash( $_GET['channel'] ?? 'suite' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- callers verify the nonce first.
	}

	private static function back( string $channel, array $args = array() ): void {
		$url = self::channels()[ $channel ]['url'] ?? admin_url();
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	public static function handle_dismiss(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( self::DISMISS_ACTION . '_' . $id );
		if ( ! self::can_manage() ) {
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				MMI_Audit_Log::denied( 'mmi-shared', 'notification.dismiss' );
			}
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ) );
		}
		self::dismiss( $id );
		self::back( self::request_channel() );
	}

	public static function handle_send_now(): void {
		check_admin_referer( self::SEND_ACTION );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ) );
		}
		$channel = self::request_channel();
		self::back( $channel, array( 'mmi_summary_sent' => self::send_digest( $channel, true ) ? '1' : '0' ) );
	}

	public static function handle_preview(): void {
		check_admin_referer( self::PREVIEW_ACTION );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ) );
		}
		$channel = self::request_channel();
		$last    = (string) MMI_Settings::get( self::LAST_DIGEST_PREFIX . $channel, '' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo self::render_email( $channel, self::snapshot( $channel, $last !== '' ? $last : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in MMI_Email_Templates.
		exit;
	}

	/* ── Storage ─────────────────────────────────────────────────────────── */

	private static function table(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'mmi_notifications';
		if ( ! self::$table_ensured ) {
			self::$table_ensured = true;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta(
					"CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(20) NOT NULL DEFAULT 'suite',
            source VARCHAR(64) NOT NULL DEFAULT '',
            nkey VARCHAR(191) NOT NULL DEFAULT '',
            severity VARCHAR(10) NOT NULL DEFAULT 'info',
            title VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            url VARCHAR(500) NOT NULL DEFAULT '',
            occurrences INT(10) UNSIGNED NOT NULL DEFAULT 1,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            resolved_at DATETIME NULL DEFAULT NULL,
            dismissed TINYINT(1) NOT NULL DEFAULT 0,
            emailed_at DATETIME NULL DEFAULT NULL,
            urgent_sent_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY channel_nkey (channel, nkey),
            KEY channel_open (channel, resolved_at),
            KEY urgent (channel, urgent_sent_at)
        ) {$wpdb->get_charset_collate()};"
				);
				if ( class_exists( 'MMI_Logger' ) ) {
					MMI_Logger::info( 'mmi_notifications table created', array(), 'database', 'MMI_Notifications' );
				}
			}
		}
		return $table;
	}
}

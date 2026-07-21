<?php
/**
 * Chatzio license client — talks to PCL License Server.
 *
 * Public, stable API for the rest of the plugin:
 *   Chatzio_License::is_pro()              true if license is valid right now
 *   Chatzio_License::status()              'valid' | 'invalid' | 'wrong_product' | 'expired' | 'wrong_domain' | 'not_activated' | 'unknown'
 *   Chatzio_License::variant()             'monthly' | 'lifetime' | 'free' | ''
 *   Chatzio_License::expires_at()          'Y-m-d H:i:s' UTC or ''
 *   Chatzio_License::last_check()          unix ts of last successful validate, 0 if never
 *
 * Admin actions (admin-post.php):
 *   chatzio_license_save        — save key
 *   chatzio_license_activate    — POST .../licenses/activate
 *   chatzio_license_validate    — POST .../licenses/validate (manual heartbeat)
 *   chatzio_license_deactivate  — POST .../licenses/deactivate (frees the key)
 *
 * Storage:
 *   option 'chatzio_license' = [
 *     'key'         => string,
 *     'status'      => string,
 *     'variant'     => string,
 *     'expires_at'  => string (UTC mysql) or '',
 *     'last_check'  => int unix ts,
 *     'last_message'=> string,
 *     'last_code'   => string,
 *   ]
 *
 * Network: never fail-open silently. We cache the last good check; if the server
 * is unreachable we keep the previous status until grace period expires.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatzio_License {

	const OPTION_KEY      = 'chatzio_license';
	const CRON_HOOK       = 'chatzio_license_validate';
	const TRANSIENT_LOCK  = 'chatzio_license_lock';
	const ADMIN_FLASH     = 'chatzio_license_flash';
	const GRACE_DAYS      = 3; // allow up to 3 days of network failure before considering invalid.

	/**
	 * Bootstrap hooks. Called from the main plugin file.
	 */
	public static function init() {
		add_action( 'admin_post_chatzio_license_save',       [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_chatzio_license_activate',   [ __CLASS__, 'handle_activate' ] );
		add_action( 'admin_post_chatzio_license_validate',   [ __CLASS__, 'handle_validate' ] );
		add_action( 'admin_post_chatzio_license_deactivate', [ __CLASS__, 'handle_deactivate' ] );
		add_action( 'admin_post_chatzio_license_selftest',   [ __CLASS__, 'handle_selftest' ] );

		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_validate' ] );
		add_action( 'admin_init',    [ __CLASS__, 'maybe_throttled_validate' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
	}

	// ---------------------------------------------------------------------
	// Storage helpers.
	// ---------------------------------------------------------------------

	public static function get_state() {
		$defaults = [
			'key'          => '',
			'status'       => 'unknown',
			'variant'      => '',
			'expires_at'   => '',
			'last_check'   => 0,
			'last_message' => '',
			'last_code'    => '',
		];
		$opt = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $opt ) ) {
			$opt = [];
		}
		return array_merge( $defaults, $opt );
	}

	private static function update_state( array $patch ) {
		$cur = self::get_state();
		$new = array_merge( $cur, $patch );
		update_option( self::OPTION_KEY, $new, false );
		return $new;
	}

	public static function key()        { $s = self::get_state(); return (string) $s['key']; }
	public static function status()     { $s = self::get_state(); return (string) $s['status']; }
	public static function variant()    { $s = self::get_state(); return (string) $s['variant']; }
	public static function expires_at() { $s = self::get_state(); return (string) $s['expires_at']; }
	public static function last_check() { $s = self::get_state(); return (int) $s['last_check']; }

	/**
	 * True if the plugin should currently treat itself as PRO.
	 *
	 * Rules:
	 *  - Status must be 'valid'.
	 *  - For monthly: expires_at must be in the future (or empty for safety).
	 *  - During network failure we still respect the last good 'valid' status
	 *    until GRACE_DAYS pass.
	 */
	public static function is_pro() {
		$s = self::get_state();
		if ( $s['status'] !== 'valid' ) {
			return false;
		}
		if ( ! empty( $s['expires_at'] ) ) {
			$exp = strtotime( $s['expires_at'] . ' UTC' );
			if ( $exp && $exp < time() ) {
				return false;
			}
		}
		// Within grace? last_check older than 7 + GRACE days = treat as expired.
		$max_age = ( 7 + self::GRACE_DAYS ) * DAY_IN_SECONDS;
		if ( $s['last_check'] > 0 && ( time() - (int) $s['last_check'] ) > $max_age ) {
			return false;
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// REST plumbing.
	// ---------------------------------------------------------------------

	/**
	 * Low-level POST to PCL.
	 *
	 * @return array{ok:bool, code:int, body:array, error:string}
	 */
	public static function request( $path, array $body ) {
		$url = chatzio_pcl_api_base() . $path;

		$args = [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		];

		$res = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'code' => 0, 'body' => [], 'error' => $res->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$json = [];
		}

		return [
			'ok'    => ( $code >= 200 && $code < 300 ),
			'code'  => $code,
			'body'  => $json,
			'error' => $code >= 400 && isset( $json['message'] ) ? (string) $json['message'] : '',
		];
	}

	private static function payload( $key = null ) {
		$state = self::get_state();
		$key   = $key !== null ? $key : $state['key'];
		return [
			'license_key'  => (string) $key,
			'product_slug' => chatzio_pcl_product_slug(),
			'site_url'     => chatzio_pcl_site_url(),
		];
	}

	// ---------------------------------------------------------------------
	// API operations.
	// ---------------------------------------------------------------------

	public static function activate( $key = null ) {
		$key = $key !== null ? $key : self::key();
		if ( $key === '' ) {
			return [ 'ok' => false, 'code' => 0, 'body' => [], 'error' => __( 'Please enter a license key first.', 'chatzio-ai' ) ];
		}

		$res = self::request( '/licenses/activate', self::payload( $key ) );

		if ( $res['ok'] ) {
			self::update_state( [
				'key'          => $key,
				'status'       => 'valid',
				'last_check'   => time(),
				'last_message' => isset( $res['body']['message'] ) ? (string) $res['body']['message'] : __( 'Activated.', 'chatzio-ai' ),
				'last_code'    => 'activated',
			] );
			// Pull fresh validate to learn variant / expiry.
			self::validate();
			return $res;
		}

		// HTTP error — do not persist a typed key; only update state if this key was already stored.
		$code = '';
		if ( isset( $res['body']['code'] ) ) {
			$code = (string) $res['body']['code'];
		} elseif ( $res['code'] === 409 ) {
			$code = 'pcl_other_site';
		} elseif ( $res['code'] === 404 ) {
			$code = 'pcl_invalid_key';
		} elseif ( $res['code'] === 403 ) {
			$code = 'pcl_expired';
		}
		$patch = [
			'status'       => 'invalid',
			'last_check'   => time(),
			'last_message' => $res['error'] !== '' ? $res['error'] : __( 'Could not activate license.', 'chatzio-ai' ),
			'last_code'    => $code,
		];
		if ( self::key() === $key ) {
			self::update_state( $patch );
		}
		return $res;
	}

	public static function validate() {
		if ( self::key() === '' ) {
			self::update_state( [
				'status'       => 'unknown',
				'last_check'   => time(),
				'last_message' => __( 'No license key set.', 'chatzio-ai' ),
				'last_code'    => 'no_key',
			] );
			return [ 'ok' => false, 'code' => 0, 'body' => [], 'error' => 'no_key' ];
		}

		$res = self::request( '/licenses/validate', self::payload() );

		if ( $res['ok'] && ! empty( $res['body']['valid'] ) ) {
			$lic = isset( $res['body']['license'] ) && is_array( $res['body']['license'] ) ? $res['body']['license'] : [];
			self::update_state( [
				'status'       => 'valid',
				'variant'      => isset( $lic['variant'] ) ? (string) $lic['variant'] : self::variant(),
				'expires_at'   => isset( $lic['expires_at'] ) && $lic['expires_at'] ? (string) $lic['expires_at'] : '',
				'last_check'   => time(),
				'last_message' => __( 'License is valid.', 'chatzio-ai' ),
				'last_code'    => 'valid',
			] );
			return $res;
		}

		// Server reachable but says not valid.
		if ( $res['code'] >= 200 && $res['code'] < 500 ) {
			$code = isset( $res['body']['code'] ) ? (string) $res['body']['code'] : 'invalid';
			$msg  = isset( $res['body']['message'] ) ? (string) $res['body']['message'] : __( 'License validation failed.', 'chatzio-ai' );
			$status_map = [
				'expired'         => 'expired',
				'wrong_domain'    => 'wrong_domain',
				'not_activated'   => 'not_activated',
				'invalid'         => 'invalid',
				'wrong_product'   => 'wrong_product',
				'pcl_invalid_key' => 'invalid',
				'pcl_expired'     => 'expired',
				'pcl_wrong_product' => 'wrong_product',
			];
			$status = isset( $status_map[ $code ] ) ? $status_map[ $code ] : 'invalid';
			self::update_state( [
				'status'       => $status,
				'last_check'   => time(),
				'last_message' => $msg,
				'last_code'    => $code,
			] );
			return $res;
		}

		// Network / 5xx — leave previous status, just log.
		self::update_state( [
			'last_message' => $res['error'] !== '' ? $res['error'] : __( 'License server unreachable.', 'chatzio-ai' ),
			'last_code'    => 'network',
		] );
		return $res;
	}

	public static function deactivate() {
		if ( self::key() === '' ) {
			return [ 'ok' => true, 'code' => 200, 'body' => [], 'error' => '' ];
		}
		$res = self::request( '/licenses/deactivate', self::payload() );
		if ( $res['ok'] ) {
			self::update_state( [
				'key'          => '',
				'status'       => 'unknown',
				'variant'      => '',
				'expires_at'   => '',
				'last_check'   => time(),
				'last_message' => __( 'License removed from this site. Enter a key again when you are ready to activate.', 'chatzio-ai' ),
				'last_code'    => 'deactivated',
			] );
		} else {
			self::update_state( [
				'last_message' => $res['error'] !== '' ? $res['error'] : __( 'Could not deactivate license.', 'chatzio-ai' ),
				'last_code'    => 'deactivate_fail',
			] );
		}
		return $res;
	}

	// ---------------------------------------------------------------------
	// Cron + admin_init heartbeat.
	// ---------------------------------------------------------------------

	public static function cron_validate() {
		if ( self::key() === '' ) {
			return;
		}
		self::validate();
	}

	/**
	 * Quietly validate at most once per 12h on admin pageviews so that if the
	 * cron is broken (very common on small WP installs) we still detect
	 * subscription expiry within a day.
	 */
	public static function maybe_throttled_validate() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( self::key() === '' ) {
			return;
		}
		if ( get_transient( self::TRANSIENT_LOCK ) ) {
			return;
		}
		set_transient( self::TRANSIENT_LOCK, 1, 12 * HOUR_IN_SECONDS );
		self::validate();
	}

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ---------------------------------------------------------------------
	// Admin handlers.
	// ---------------------------------------------------------------------

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'chatzio-ai' ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect( array $args = [] ) {
		$url = add_query_arg(
			array_merge( [ 'page' => 'chatzio-license' ], $args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Flash a one-shot admin message to the license page.
	 */
	private static function flash( $type, $message, array $meta = [] ) {
		set_transient(
			self::ADMIN_FLASH . '_' . get_current_user_id(),
			array_merge(
				[ 'type' => $type, 'message' => (string) $message ],
				$meta
			),
			60
		);
	}

	public static function consume_flash() {
		$key   = self::ADMIN_FLASH . '_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( $flash ) {
			delete_transient( $key );
			return $flash;
		}
		return null;
	}

	public static function handle_save() {
		self::guard( 'chatzio_license_save' );
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$key = trim( $key );
		self::update_state( [ 'key' => $key, 'status' => 'unknown', 'last_message' => '', 'last_code' => '' ] );
		self::redirect( [ 'updated' => 'saved' ] );
	}

	/**
	 * Activate handler — fail-closed.
	 *
	 * Contract:
	 *   - We never persist a user-typed key unless the license server accepts it.
	 *   - On failure, previous (good) state is preserved and a clear error is shown.
	 *   - On success, key + status + variant/expiry are updated atomically.
	 */
	public static function handle_activate() {
		self::guard( 'chatzio_license_activate' );

		$raw = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$key = trim( $raw );

		if ( $key === '' ) {
			self::flash( 'error', __( 'Please paste your license key before activating.', 'chatzio-ai' ) );
			self::redirect();
		}

		// Talk to PCL WITHOUT touching saved state first.
		$base = chatzio_pcl_api_base();
		if ( $base === '' ) {
			self::flash( 'error', __( 'License server URL is not configured.', 'chatzio-ai' ) );
			self::redirect();
		}

		$res = self::request( '/licenses/activate', [
			'license_key'  => $key,
			'product_slug' => chatzio_pcl_product_slug(),
			'site_url'     => chatzio_pcl_site_url(),
		] );

		if ( ! $res['ok'] ) {
			// Surface the best message we can find without persisting the key.
			$msg = '';
			if ( isset( $res['body']['message'] ) && $res['body']['message'] !== '' ) {
				$msg = (string) $res['body']['message'];
			} elseif ( $res['error'] !== '' ) {
				$msg = (string) $res['error'];
			} else {
				$msg = __( 'Could not activate — license is not valid for this site.', 'chatzio-ai' );
			}
			self::flash(
				'error',
				$msg,
				[
					'entered_key' => $key,
				]
			);
			self::redirect( [ 'updated' => 'activate_fail' ] );
		}

		// Server accepted the key — persist it and pull full status.
		$body    = isset( $res['body'] ) ? (array) $res['body'] : [];
		$variant = isset( $body['variant'] ) ? (string) $body['variant'] : '';
		$expires = isset( $body['expires_at'] ) ? (string) $body['expires_at'] : '';

		self::update_state( [
			'key'          => $key,
			'status'       => 'valid',
			'variant'      => $variant,
			'expires_at'   => $expires,
			'last_check'   => time(),
			'last_message' => __( 'License activated on this site.', 'chatzio-ai' ),
			'last_code'    => 'activated',
		] );

		// Refresh variant/expiry authoritatively.
		self::validate();

		self::flash( 'success', __( 'License activated. Pro features are now unlocked on this site.', 'chatzio-ai' ) );
		self::redirect( [ 'updated' => 'activated' ] );
	}

	public static function handle_validate() {
		self::guard( 'chatzio_license_validate' );
		$res = self::validate();
		if ( ! empty( $res['ok'] ) ) {
			self::flash( 'success', __( 'License re-checked. Everything looks good.', 'chatzio-ai' ) );
		} else {
			$state = self::get_state();
			$msg   = $state['last_message'] !== '' ? $state['last_message'] : __( 'License check completed with an issue.', 'chatzio-ai' );
			self::flash( 'error', $msg );
		}
		self::redirect( [ 'updated' => 'validated' ] );
	}

	public static function handle_deactivate() {
		self::guard( 'chatzio_license_deactivate' );
		$had_key = self::key() !== '';
		$res = self::deactivate();
		if ( $res['ok'] ) {
			self::flash( 'success', __( 'License was deactivated on the server and the key was cleared from this site.', 'chatzio-ai' ) );
		} else {
			// User explicitly requested local removal; always clear local key even if remote release fails.
			self::update_state( [
				'key'          => '',
				'status'       => 'unknown',
				'variant'      => '',
				'expires_at'   => '',
				'last_check'   => time(),
				'last_message' => __( 'License key cleared locally, but server deactivation failed. Contact support if this key stays locked on another site.', 'chatzio-ai' ),
				'last_code'    => 'deactivate_local_only',
			] );
			$msg = $res['error'] !== '' ? $res['error'] : __( 'Could not deactivate on server. Local key was removed.', 'chatzio-ai' );
			self::flash( 'error', $msg );
		}
		if ( ! $had_key ) {
			self::flash( 'success', __( 'No stored key found. Nothing to deactivate.', 'chatzio-ai' ) );
		}
		self::redirect( [ 'updated' => $res['ok'] ? 'deactivated' : 'deactivate_fail' ] );
	}

	public static function handle_selftest() {
		self::guard( 'chatzio_license_selftest' );
		$results = self::run_selftest();
		set_transient( 'chatzio_license_selftest_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );
		self::redirect( [ 'selftest' => '1' ] );
	}

	// ---------------------------------------------------------------------
	// Self test (lightweight installer / config sanity checks).
	// ---------------------------------------------------------------------

	/**
	 * Verify the install is wired correctly. Each test returns:
	 *   [ name, ok (bool), detail ]
	 *
	 * Tests are SAFE: they do not change any license state on PCL.
	 */
	public static function run_selftest() {
		$tests = [];

		$base = chatzio_pcl_api_base();
		$tests[] = [
			'name'   => 'Config: PCL API base URL is set',
			'ok'     => $base !== '' && preg_match( '#^https?://#i', $base ),
			'detail' => $base !== '' ? $base : 'EMPTY',
		];

		$slug = chatzio_pcl_product_slug();
		$tests[] = [
			'name'   => 'Config: product slug is set',
			'ok'     => $slug !== '',
			'detail' => $slug !== '' ? $slug : 'EMPTY',
		];

		$site = chatzio_pcl_site_url();
		$tests[] = [
			'name'   => 'Config: site URL resolves',
			'ok'     => filter_var( $site, FILTER_VALIDATE_URL ) !== false,
			'detail' => (string) $site,
		];

		$tests[] = [
			'name'   => 'Hook: cron job is scheduled',
			'ok'     => (bool) wp_next_scheduled( self::CRON_HOOK ),
			'detail' => wp_next_scheduled( self::CRON_HOOK ) ? 'next: ' . gmdate( 'Y-m-d H:i:s', wp_next_scheduled( self::CRON_HOOK ) ) . ' UTC' : 'NOT SCHEDULED',
		];

		// Try OPTIONS / GET on REST root (no license action).
		$probe = wp_remote_get( $base, [ 'timeout' => 10 ] );
		$probe_ok = ! is_wp_error( $probe ) && (int) wp_remote_retrieve_response_code( $probe ) > 0;
		$tests[] = [
			'name'   => 'Network: license server reachable',
			'ok'     => $probe_ok,
			'detail' => is_wp_error( $probe ) ? $probe->get_error_message() : ( 'HTTP ' . wp_remote_retrieve_response_code( $probe ) ),
		];

		// Validate endpoint exists (we expect 4xx if no key, not connection error).
		$echo = wp_remote_post(
			$base . '/licenses/validate',
			[
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'license_key'  => 'SELFTEST-NOOP-KEY',
					'product_slug' => $slug,
					'site_url'     => $site,
				] ),
			]
		);
		$echo_code = is_wp_error( $echo ) ? 0 : (int) wp_remote_retrieve_response_code( $echo );
		$tests[] = [
			'name'   => 'API: /licenses/validate responds',
			'ok'     => $echo_code > 0,
			'detail' => is_wp_error( $echo ) ? $echo->get_error_message() : ( 'HTTP ' . $echo_code ),
		];

		// Class wiring.
		$tests[] = [
			'name'   => 'Class: Chatzio_License available',
			'ok'     => class_exists( 'Chatzio_License' ),
			'detail' => 'OK',
		];

		// Storage.
		$tests[] = [
			'name'   => 'Storage: option chatzio_license readable',
			'ok'     => is_array( get_option( self::OPTION_KEY, [] ) ),
			'detail' => 'array',
		];

		// Public helper exists.
		$tests[] = [
			'name'   => 'API: Chatzio_License::is_pro() callable',
			'ok'     => is_callable( [ __CLASS__, 'is_pro' ] ),
			'detail' => self::is_pro() ? 'currently PRO' : 'currently FREE',
		];

		return $tests;
	}

	// ---------------------------------------------------------------------
	// Admin notice on every screen when license is in trouble.
	// ---------------------------------------------------------------------

	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't duplicate on the license screen itself — it already shows full status.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && strpos( (string) $screen->id, 'chatzio-license' ) !== false ) {
			return;
		}

		if ( self::is_pro() ) {
			return;
		}

		$state = self::get_state();
		$url   = add_query_arg( [ 'page' => 'chatzio-license' ], admin_url( 'admin.php' ) );

		if ( $state['key'] === '' ) {
			return; // free mode (no key) should not nag across admin pages.
		}
		$map = [
			'expired'        => __( 'Your Chatzio license has expired. Paid features are currently unavailable until you renew.', 'chatzio-ai' ),
			'invalid'        => __( 'Your Chatzio license key is not valid. Please check and activate again.', 'chatzio-ai' ),
			'wrong_product'  => __( 'This license key belongs to another product. Check that you purchased Chatzio and that your store uses the product slug “chatzio”.', 'chatzio-ai' ),
			'wrong_domain'   => __( 'Your Chatzio license is activated on a different domain. Paid features are unavailable on this site.', 'chatzio-ai' ),
			'not_activated'  => __( 'Your Chatzio license is not activated on this domain. Activate it from the license page.', 'chatzio-ai' ),
		];
		$msg = isset( $map[ $state['status'] ] ) ? $map[ $state['status'] ] : __( 'Your Chatzio license is not active.', 'chatzio-ai' );

		echo '<div class="notice notice-error chatzio-license-admin-notice"><p><strong>' . esc_html__( 'Chatzio — license required.', 'chatzio-ai' ) . '</strong> ';
		echo esc_html( $msg );
		echo ' <a class="button button-primary" style="margin-left:8px;" href="' . esc_url( $url ) . '">' . esc_html__( 'Enter license', 'chatzio-ai' ) . '</a>';
		echo '</p></div>';
	}
}

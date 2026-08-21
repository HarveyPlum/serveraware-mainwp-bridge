<?php
/**
 * Plugin Name: ServerAware MainWP Bridge
 * Plugin URI: https://harveyplum.com
 * Description: Adds narrowly scoped MainWP REST API v2 endpoints for cache detection and confirmed per-site cache purges from ServerAware.
 * Version: 1.0.1
 * Author: Harvey Plum
 * Author URI: https://harveyplum.com
 * GitHub Plugin URI: https://github.com/HarveyPlum/serveraware-mainwp-bridge
 * Update URI: https://github.com/HarveyPlum/serveraware-mainwp-bridge
 * Primary Branch: main
 * Release Asset: true
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: serveraware-mainwp-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ServerAware_MainWP_Bridge {
	const VERSION   = '1.0.1';
	const NAMESPACE = 'mainwp/v2';
	const REST_BASE = 'serveraware-bridge';

	/**
	 * Register the bridge after WordPress and MainWP have registered REST support.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
	}

	/**
	 * Register API-key authenticated MainWP v2 routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/cache',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'cache_metadata' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'site_ids' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_site_ids' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/cache/(?P<id>[\d]+)/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'purge_cache' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Reuse MainWP's API-key authentication and its read/write permission model.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public static function permissions_check( $request ) {
		if ( ! class_exists( 'MainWP_REST_Authentication' ) ) {
			return new WP_Error(
				'serveraware_mainwp_unavailable',
				__( 'MainWP REST API v2 is unavailable.', 'serveraware-mainwp-bridge' ),
				array( 'status' => 503 )
			);
		}

		$allowed = MainWP_REST_Authentication::get_instance()->is_valid_permissions( $request );
		if ( true === $allowed ) {
			return true;
		}

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return new WP_Error(
			'serveraware_mainwp_forbidden',
			__( 'The supplied MainWP API credentials do not permit this request.', 'serveraware-mainwp-bridge' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Report bridge availability without returning secrets or child credentials.
	 */
	public static function status() {
		return rest_ensure_response(
			array(
				'available' => self::mainwp_available(),
				'version'   => self::VERSION,
				'capabilities' => array(
					'cache_metadata' => true,
					'cache_purge'    => true,
				),
			)
		);
	}

	/**
	 * Return Cache Control metadata for only the requested, user-accessible sites.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cache_metadata( $request ) {
		$dependency_error = self::dependency_error();
		if ( is_wp_error( $dependency_error ) ) {
			return $dependency_error;
		}

		$ids   = array_values( array_unique( array_map( 'absint', explode( ',', $request['site_ids'] ) ) ) );
		$ids   = array_slice( array_filter( $ids ), 0, 500 );
		$sites = array();

		foreach ( $ids as $id ) {
			$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $id );
			if ( empty( $website ) || ! \MainWP\Dashboard\MainWP_System_Utility::can_edit_website( $website ) ) {
				continue;
			}

			$raw_solution = \MainWP\Dashboard\MainWP_DB::instance()->get_website_option(
				$website,
				'mainwp_cache_control_cache_solution',
				''
			);
			$last_purged = \MainWP\Dashboard\MainWP_DB::instance()->get_website_option(
				$website,
				'mainwp_cache_control_last_purged',
				''
			);

			$sites[] = array(
				'site_id'            => (int) $website->id,
				'cache_solution'     => self::friendly_cache_solution( $raw_solution ),
				'cache_solution_raw' => is_scalar( $raw_solution ) ? (string) $raw_solution : '',
				'last_purged'        => is_scalar( $last_purged ) ? (string) $last_purged : '',
				'purge_supported'    => true,
			);
		}

		return rest_ensure_response(
			array(
				'version' => self::VERSION,
				'sites'   => $sites,
			)
		);
	}

	/**
	 * Ask the selected MainWP Child site to run its registered cache purge action.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function purge_cache( $request ) {
		$dependency_error = self::dependency_error();
		if ( is_wp_error( $dependency_error ) ) {
			return $dependency_error;
		}

		$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( absint( $request['id'] ) );
		if ( empty( $website ) ) {
			return new WP_Error(
				'serveraware_site_not_found',
				__( 'The requested MainWP child site was not found.', 'serveraware-mainwp-bridge' ),
				array( 'status' => 404 )
			);
		}

		if ( ! \MainWP\Dashboard\MainWP_System_Utility::can_edit_website( $website ) ) {
			return new WP_Error(
				'serveraware_site_forbidden',
				__( 'This API-key user cannot manage the requested child site.', 'serveraware-mainwp-bridge' ),
				array( 'status' => 403 )
			);
		}

		if ( ! empty( $website->suspended ) ) {
			return new WP_Error(
				'serveraware_site_suspended',
				__( 'The requested MainWP child site is suspended.', 'serveraware-mainwp-bridge' ),
				array( 'status' => 409 )
			);
		}

		try {
			$result = \MainWP\Dashboard\MainWP_Connect::fetch_url_authed(
				$website,
				'cache_purge_action',
				array()
			);
		} catch ( Throwable $error ) {
			return new WP_Error(
				'serveraware_cache_purge_failed',
				$error->getMessage(),
				array( 'status' => 502 )
			);
		}

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			return new WP_Error(
				'serveraware_cache_purge_failed',
				sanitize_text_field( (string) $result['error'] ),
				array( 'status' => 502 )
			);
		}

		do_action( 'serveraware_mainwp_bridge_cache_purged', (int) $website->id, $result );

		return rest_ensure_response(
			array(
				'success'   => true,
				'site_id'   => (int) $website->id,
				'purged_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Validate a comma-separated list of positive IDs and cap request size.
	 */
	public static function validate_site_ids( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d+(,\d+)*$/', $value ) ) {
			return false;
		}
		return count( explode( ',', $value ) ) <= 500;
	}

	/**
	 * Convert Cache Control's stored identifier to a useful label.
	 */
	private static function friendly_cache_solution( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$normalized = strtolower( str_replace( array( '_', '-' ), ' ', $value ) );
		if ( false !== strpos( $normalized, 'litespeed' ) || false !== strpos( $normalized, 'lite speed' ) ) {
			return 'LiteSpeed Cache';
		}
		if ( false !== strpos( $normalized, 'wp rocket' ) ) {
			return 'WP Rocket';
		}
		if ( false !== strpos( $normalized, 'w3 total' ) ) {
			return 'W3 Total Cache';
		}
		if ( false !== strpos( $normalized, 'wp super' ) ) {
			return 'WP Super Cache';
		}

		return sanitize_text_field( $value );
	}

	private static function mainwp_available() {
		return self::mainwp_core_available()
			&& class_exists( 'MainWP_REST_Authentication' );
	}

	private static function mainwp_core_available() {
		return class_exists( '\\MainWP\\Dashboard\\MainWP_DB' )
			&& class_exists( '\\MainWP\\Dashboard\\MainWP_Connect' )
			&& class_exists( '\\MainWP\\Dashboard\\MainWP_System_Utility' );
	}

	private static function dependency_error() {
		if ( self::mainwp_available() ) {
			return true;
		}
		return new WP_Error(
			'serveraware_mainwp_unavailable',
			__( 'ServerAware MainWP Bridge requires an active, current MainWP Dashboard plugin.', 'serveraware-mainwp-bridge' ),
			array( 'status' => 503 )
		);
	}

	public static function dependency_notice() {
		if ( self::mainwp_core_available() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'ServerAware MainWP Bridge requires the MainWP Dashboard plugin to be active.', 'serveraware-mainwp-bridge' )
			. '</p></div>';
	}
}

ServerAware_MainWP_Bridge::init();

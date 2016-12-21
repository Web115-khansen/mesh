<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Mesh_Cache_Helper class.
 *
 * @class 		Mesh_Cache_Helper
 * @version		1.2.0
 * @package		Mesh/Classes
 * @category	Class
 * @author 		Linchpin
 */
class Mesh_Cache_Helper {

	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'before_mesh_init', array( __CLASS__, 'prevent_caching' ) );
		add_action( 'delete_version_transients', array( __CLASS__, 'delete_version_transients' ) );
	}

	/**
	 * Get prefix for use with wp_cache_set. Allows all cache in a group to be invalidated at once.
	 * @param  string $group
	 * @return string
	 */
	public static function get_cache_prefix( $group ) {
		// Get cache key - uses cache key wc_orders_cache_prefix to invalidate when needed
		$prefix = wp_cache_get( 'wc_' . $group . '_cache_prefix', $group );

		if ( false === $prefix ) {
			$prefix = 1;
			wp_cache_set( 'mesh_' . $group . '_cache_prefix', $prefix, $group );
		}

		return 'mesh_cache_' . $prefix . '_';
	}

	/**
	 * Increment group cache prefix (invalidates cache).
	 * @param  string $group
	 */
	public static function incr_cache_prefix( $group ) {
		wp_cache_incr( 'mesh_' . $group . '_cache_prefix', 1, $group );
	}

	/**
	 * Get transient version.
	 *
	 * When using transients with unpredictable names, e.g. those containing an md5.
	 * hash in the name, we need a way to invalidate them all at once.
	 *
	 * When using default WP transients we're able to do this with a DB query to.
	 * delete transients manually.
	 *
	 * With external cache however, this isn't possible. Instead, this function is used.
	 * to append a unique string (based on time()) to each transient. When transients.
	 * are invalidated, the transient version will increment and data will be regenerated.
	 *
	 * Adapted from ideas in http://tollmanz.com/invalidation-schemes/.
	 *
	 * @param  string  $group   Name for the group of transients we need to invalidate
	 * @param  boolean $refresh true to force a new version
	 * @return string transient version based on time(), 10 digits
	 */
	public static function get_transient_version( $group, $refresh = false ) {
		$transient_name  = $group . '-transient-version';
		$transient_value = get_transient( $transient_name );

		if ( false === $transient_value || true === $refresh ) {
			self::delete_version_transients( $transient_value );
			set_transient( $transient_name, $transient_value = time() );
		}
		return $transient_value;
	}

	/**
	 * When the transient version increases, this is used to remove all past transients to avoid filling the DB.
	 *
	 * Note; this only works on transients appended with the transient version, and when object caching is not being used.
	 *
	 * @since 1.2.0
	 */
	public static function delete_version_transients( $version = '' ) {
		if ( ! wp_using_ext_object_cache() && ! empty( $version ) ) {
			global $wpdb;

			$limit    = apply_filters( 'mesh_delete_version_transients_limit', 1000 );
			$affected = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT %d;", "\_transient\_%" . $version, $limit ) );

			// If affected rows is equal to limit, there are more rows to delete. Delete in 10 secs.
			if ( $affected === $limit ) {
				wp_schedule_single_event( time() + 10, 'delete_version_transients', array( $version ) );
			}
		}
	}

	/**
	 * Get the page name/id for a WC page.
	 * @param  string $wc_page
	 * @return array
	 */
	private static function get_page_uris( $mesh_page ) {
		$mesh_page_uris = array();

		if ( ( $page_id = wc_get_page_id( $mesh_page ) ) && $page_id > 0 && ( $page = get_post( $page_id ) ) ) {
			$mesh_page_uris[] = 'p=' . $page_id;
			$mesh_page_uris[] = '/' . $page->post_name . '/';
		}

		return $mesh_page_uris;
	}

	/**
	 * Prevent caching on dynamic pages.
	 */
	public static function prevent_caching() {

		if ( ! is_blog_installed() ) {
			return;
		}

		if ( false === ( $mesh_page_uris = get_transient( 'mesh_cache_excluded_uris' ) ) ) {
			$mesh_page_uris   = array_filter( array_merge( self::get_page_uris( 'cart' ), self::get_page_uris( 'checkout' ), self::get_page_uris( 'myaccount' ) ) );
			set_transient( 'mesh_cache_excluded_uris', $mesh_page_uris );
		}

		if ( is_array( $mesh_page_uris ) ) {
			foreach ( $mesh_page_uris as $uri ) {
				if ( stristr( trailingslashit( $_SERVER['REQUEST_URI'] ), $uri ) ) {
					self::nocache();
					break;
				}
			}
		}
	}

	/**
	 * Set nocache constants and headers.
	 * @access private
	 */
	private static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( "DONOTCACHEPAGE", true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( "DONOTCACHEOBJECT", true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( "DONOTCACHEDB", true );
		}
		nocache_headers();
	}

	/**
	 * notices function.
	 */
	public static function notices() {
		if ( ! function_exists( 'w3tc_pgcache_flush' ) || ! function_exists( 'w3_instance' ) ) {
			return;
		}

		$config   = w3_instance( 'W3_Config' );
		$enabled  = $config->get_integer( 'dbcache.enabled' );
		$settings = array_map( 'trim', $config->get_array( 'dbcache.reject.sql' ) );

		if ( $enabled && ! in_array( '_mesh_session_', $settings ) ) {
			?>
			<div class="error">
				<p><?php printf( __( 'In order for <strong>database caching</strong> to work with Mesh you must add %1$s to the "Ignored Query Strings" option in <a href="%2$s">W3 Total Cache settings</a>.', 'mesh' ), '<code>_mesh_session_</code>', admin_url( 'admin.php?page=w3tc_dbcache' ) ); ?></p>
			</div>
			<?php
		}
	}
}

Mesh_Cache_Helper::init();
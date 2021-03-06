<?php
/**
 * Module Name: Asset CDN
 * Module Description: Serve static assets from our servers
 * Sort Order: 26
 * Recommendation Order: 1
 * First Introduced: 6.6
 * Requires Connection: No
 * Auto Activate: No
 * Module Tags: Photos and Videos, Appearance, Recommended
 * Feature: Recommended, Appearance
 * Additional Search Queries: photon, image, cdn, performance, speed, assets
 */

$GLOBALS['concatenate_scripts'] = false;

Jetpack::dns_prefetch( array(
	'//c0.wp.com',
) );

class Jetpack_Photon_Static_Assets_CDN {
	const CDN = 'https://c0.wp.com/';

	/**
	 * Sets up action handlers needed for Jetpack CDN.
	 */
	public static function go() {
		add_action( 'wp_print_scripts', array( __CLASS__, 'cdnize_assets' ) );
		add_action( 'wp_print_styles', array( __CLASS__, 'cdnize_assets' ) );
		add_action( 'admin_print_scripts', array( __CLASS__, 'cdnize_assets' ) );
		add_action( 'admin_print_styles', array( __CLASS__, 'cdnize_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'cdnize_assets' ) );
	}

	/**
	 * Sets up CDN URLs for assets that are enqueued by the WordPress Core.
	 */
	public static function cdnize_assets() {
		global $wp_scripts, $wp_styles, $wp_version;

		/**
		 * Filters Jetpack CDN's Core version number and locale. Can be used to override the values
		 * that Jetpack uses to retrieve assets. Expects the values to be returned in an array.
		 *
		 * @since 6.6
		 *
		 * @param array $values array( $version  = core assets version, i.e. 4.9.8, $locale = desired locale )
		 */
		list( $version, $locale ) = apply_filters(
			'jetpack_cdn_core_version_and_locale',
			array( $wp_version, get_locale() )
		);

		if ( self::is_public_version( $version ) ) {
			$site_url = trailingslashit( site_url() );
			foreach ( $wp_scripts->registered as $handle => $thing ) {
				if ( wp_startswith( $thing->src, self::CDN ) ) {
					continue;
				}
				$src = ltrim( str_replace( $site_url, '', $thing->src ), '/' );
				if ( self::is_js_or_css_file( $src ) && in_array( substr( $src, 0, 9 ), array( 'wp-admin/', 'wp-includ' ) ) ) {
					$wp_scripts->registered[ $handle ]->src = sprintf( self::CDN . 'c/%1$s/%2$s', $version, $src );
					$wp_scripts->registered[ $handle ]->ver = null;
				}
			}
			foreach ( $wp_styles->registered as $handle => $thing ) {
				if ( wp_startswith( $thing->src, self::CDN ) ) {
					continue;
				}
				$src = ltrim( str_replace( $site_url, '', $thing->src ), '/' );
				if ( self::is_js_or_css_file( $src ) && in_array( substr( $src, 0, 9 ), array( 'wp-admin/', 'wp-includ' ) ) ) {
					$wp_styles->registered[ $handle ]->src = sprintf( self::CDN . 'c/%1$s/%2$s', $version, $src );
					$wp_styles->registered[ $handle ]->ver = null;
				}
			}
		}

		self::cdnize_plugin_assets( 'jetpack', JETPACK__VERSION );
		if ( class_exists( 'WooCommerce' ) ) {
			self::cdnize_plugin_assets( 'woocommerce', WC_VERSION );
		}
	}

	/**
	 * Sets up CDN URLs for supported plugin assets.
	 *
	 * @param String $plugin_slug plugin slug string.
	 * @param String $current_version plugin version string.
	 * @return null|bool
	 */
	public static function cdnize_plugin_assets( $plugin_slug, $current_version ) {
		global $wp_scripts, $wp_styles;

		/**
		 * Filters Jetpack CDN's plugin slug and version number. Can be used to override the values
		 * that Jetpack uses to retrieve assets. For example, when testing a development version of Jetpack
		 * the assets are not yet published, so you may need to override the version value to either
		 * trunk, or the latest available version. Expects the values to be returned in an array.
		 *
		 * @since 6.6
		 *
		 * @param array $values array( $slug = the plugin repository slug, i.e. jetpack, $version = the plugin version, i.e. 6.6 )
		 */
		list( $plugin_slug, $current_version ) = apply_filters(
			'jetpack_cdn_plugin_slug_and_version',
			array( $plugin_slug, $current_version )
		);

		$assets               = self::get_plugin_assets( $plugin_slug, $current_version );
		$plugin_directory_url = plugins_url() . '/' . $plugin_slug . '/';

		if ( is_wp_error( $assets ) || ! is_array( $assets ) ) {
			return false;
		}

		foreach ( $wp_scripts->registered as $handle => $thing ) {
			if ( wp_startswith( $thing->src, self::CDN ) ) {
				continue;
			}
			if ( wp_startswith( $thing->src, $plugin_directory_url ) ) {
				$local_path = substr( $thing->src, strlen( $plugin_directory_url ) );
				if ( in_array( $local_path, $assets, true ) ) {
					$wp_scripts->registered[ $handle ]->src = sprintf( self::CDN . 'p/%1$s/%2$s/%3$s', $plugin_slug, $current_version, $local_path );
					$wp_scripts->registered[ $handle ]->ver = null;
				}
			}
		}
		foreach ( $wp_styles->registered as $handle => $thing ) {
			if ( wp_startswith( $thing->src, self::CDN ) ) {
				continue;
			}
			if ( wp_startswith( $thing->src, $plugin_directory_url ) ) {
				$local_path = substr( $thing->src, strlen( $plugin_directory_url ) );
				if ( in_array( $local_path, $assets, true ) ) {
					$wp_styles->registered[ $handle ]->src = sprintf( self::CDN . 'p/%1$s/%2$s/%3$s', $plugin_slug, $current_version, $local_path );
					$wp_styles->registered[ $handle ]->ver = null;
				}
			}
		}
	}

	/**
	 * Returns cdn-able assets for a given plugin.
	 *
	 * @param string $plugin plugin slug string.
	 * @param string $version plugin version number string.
	 * @return array
	 */
	public static function get_plugin_assets( $plugin, $version ) {
		if ( 'jetpack' === $plugin && JETPACK__VERSION === $version ) {
			$assets = array(); // The variable will be redefined in the included file.

			include JETPACK__PLUGIN_DIR . 'modules/photon-cdn/jetpack-manifest.php';
			return $assets;
		}

		/**
		 * Used for other plugins to provide their bundled assets via filter to
		 * prevent the need of storing them in an option or an external api request
		 * to w.org.
		 *
		 * @since 6.6
		 *
		 * @param array $assets The assets array for the plugin.
		 * @param string $version The version of the plugin being requested.
		 */
		$assets = apply_filters( "jetpack_cdn_plugin_assets-{$plugin}", null, $version );
		if ( is_array( $assets ) ) {
			return $assets;
		}

		if ( ! self::is_public_version( $version ) ) {
			return false;
		}

		$cache = Jetpack_Options::get_option( 'static_asset_cdn_files', array() );
		if ( isset( $cache[ $plugin ][ $version ] ) ) {
			if ( is_array( $cache[ $plugin ][ $version ] ) ) {
				return $cache[ $plugin ][ $version ];
			}
			if ( is_numeric( $cache[ $plugin ][ $version ] ) ) {
				// Cache an empty result for up to 24h.
				if ( intval( $cache[ $plugin ][ $version ] ) + DAY_IN_SECONDS > time() ) {
					return array();
				}
			}
		}

		$url = sprintf( 'http://downloads.wordpress.org/plugin-checksums/%s/%s.json', $plugin, $version );

		if ( wp_http_supports( array( 'ssl' ) ) ) {
			$url = set_url_scheme( $url, 'https' );
		}

		$response = wp_remote_get( $url );

		$body = trim( wp_remote_retrieve_body( $response ) );
		$body = json_decode( $body, true );

		$return = time();
		if ( is_array( $body ) ) {
			$return = array_filter( array_keys( $body['files'] ), array( __CLASS__, 'is_js_or_css_file' ) );
		}

		$cache[ $plugin ]             = array();
		$cache[ $plugin ][ $version ] = $return;
		Jetpack_Options::update_option( 'static_asset_cdn_files', $cache, true );

		return $return;
	}

	/**
	 * Checks a path whether it is a JS or CSS file.
	 *
	 * @param String $path file path.
	 * @return Boolean whether the file is a JS or CSS.
	 */
	public static function is_js_or_css_file( $path ) {
		return in_array( substr( $path, -3 ), array( 'css', '.js' ), true );
	}

	/**
	 * Checks whether the version string indicates a production version.
	 *
	 * @param String  $version the version string.
	 * @param Boolean $include_beta_and_rc whether to count beta and RC versions as production.
	 * @return Boolean
	 */
	public static function is_public_version( $version, $include_beta_and_rc = false ) {
		if ( preg_match( '/^\d+(\.\d+)+$/', $version ) ) {
			// matches `1` `1.2` `1.2.3`.
			return true;
		} elseif ( $include_beta_and_rc && preg_match( '/^\d+(\.\d+)+(-(beta|rc)\d?)$/i', $version ) ) {
			// matches `1.2.3` `1.2.3-beta` `1.2.3-beta1` `1.2.3-rc` `1.2.3-rc2`.
			return true;
		}
		// unrecognized version.
		return false;
	}
}
Jetpack_Photon_Static_Assets_CDN::go();

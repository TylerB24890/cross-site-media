<?php
/**
 * Plugin Name:       Cross Site Media
 * Description:       Cross Site Media plugin to share the media library between sites in a multisite network.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Network:           true
 * Author:            Tyler Bailey
 * Author URI:        https://www.tylerb.me
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cross-site-media
 * Domain Path:       /languages
 * Update URI:        https://github.com/10up/wp-scaffold
 *
 * @package           CrossSiteMedia
 */

// Useful global constants.
define( 'CROSS_SITE_MEDIA_VERSION', '0.1.0' );
define( 'CROSS_SITE_MEDIA_URL', plugin_dir_url( __FILE__ ) );
define( 'CROSS_SITE_MEDIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CROSS_SITE_MEDIA_INC', CROSS_SITE_MEDIA_PATH . 'src/' );
define( 'CROSS_SITE_MEDIA_DIST_URL', CROSS_SITE_MEDIA_URL . 'dist/' );
define( 'CROSS_SITE_MEDIA_DIST_PATH', CROSS_SITE_MEDIA_PATH . 'dist/' );

$is_local_env = in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
$is_local_url = strpos( home_url(), '.test' ) || strpos( home_url(), '.local' );
$is_local     = $is_local_env || $is_local_url;

if ( $is_local && file_exists( __DIR__ . '/dist/fast-refresh.php' ) ) {
	require_once __DIR__ . '/dist/fast-refresh.php';

	if ( function_exists( 'TenUpToolkit\set_dist_url_path' ) ) {
		TenUpToolkit\set_dist_url_path( basename( __DIR__ ), CROSS_SITE_MEDIA_DIST_URL, CROSS_SITE_MEDIA_DIST_PATH );
	}
}

// Bail if Composer autoloader is not found.
if ( ! file_exists( CROSS_SITE_MEDIA_PATH . 'vendor/autoload.php' ) ) {
	throw new \Exception(
		'Vendor autoload file not found. Please run `composer install`.'
	);
}

require_once CROSS_SITE_MEDIA_PATH . 'vendor/autoload.php';

$plugin_core = new CrossSiteMedia\PluginCore();

// Activation/Deactivation.
register_activation_hook( __FILE__, [ $plugin_core, 'activate' ] );
register_deactivation_hook( __FILE__, [ $plugin_core, 'deactivate' ] );

// Bootstrap.
$plugin_core->setup();

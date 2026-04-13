<?php
/**
 * PluginCore module.
 *
 * @package CrossSiteMedia
 */

namespace CrossSiteMedia;

use TenupFramework\ModuleInitialization;

/**
 * PluginCore module.
 *
 * @package CrossSiteMedia
 */
class PluginCore {

	/**
	 * Default setup routine
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'init', [ $this, 'i18n' ] );
		add_action( 'init', [ $this, 'init' ], apply_filters( 'cross_site_media_init_priority', 8 ) );

		do_action( 'cross_site_media_loaded' );
	}

	/**
	 * Registers the default textdomain.
	 *
	 * @return void
	 */
	public function i18n() {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'cross-site-media' );
		load_textdomain( 'cross-site-media', WP_LANG_DIR . '/cross-site-media/cross-site-media-' . $locale . '.mo' );
		load_plugin_textdomain( 'cross-site-media', false, plugin_basename( CROSS_SITE_MEDIA_PATH ) . '/languages/' );
	}

	/**
	 * Initializes the plugin and fires an action other plugins can hook into.
	 *
	 * @return void
	 */
	public function init() {
		do_action( 'cross_site_media_before_init' );

		if ( ! class_exists( '\TenupFramework\ModuleInitialization' ) ) {
			add_action(
				'admin_notices',
				function () {
					$class = 'notice notice-error';

					printf(
						'<div class="%1$s"><p>%2$s</p></div>',
						esc_attr( $class ),
						wp_kses_post(
							__(
								'Please ensure the <a href="https://github.com/10up/wp-framework"><code>10up/wp-framework</code></a> composer package is installed.',
								'cross-site-media'
							)
						)
					);
				}
			);

			return;
		}

		ModuleInitialization::instance()->init_classes( CROSS_SITE_MEDIA_INC );
		do_action( 'cross_site_media_init' );
	}

	/**
	 * Activate the plugin
	 *
	 * @return void
	 */
	public function activate() {
		// First load the init scripts in case any rewrite functionality is being loaded
		$this->init();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate the plugin
	 *
	 * Uninstall routines should be in uninstall.php
	 *
	 * @return void
	 */
	public function deactivate() {
		// Do nothing.
	}

	/**
	 * Get an initialized class by its full class name, including namespace.
	 *
	 * @param string $class_name The class name including the namespace.
	 *
	 * @return false|\TenupFramework\ModuleInterface
	 */
	public static function get_module( $class_name ) {
		return \TenupFramework\ModuleInitialization::get_module( $class_name );
	}
}

<?php
/**
 * Environment guard.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Bails out gracefully if the plugin is activated on a single-site install.
 *
 * The other modules guard themselves on `is_multisite()` too, so this exists
 * primarily to surface a notice — without it, the plugin silently does nothing.
 */
class Environment implements ModuleInterface {
	use Module;

	/**
	 * Register only when viewing admin screens on a single-site install.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_admin() && ! is_multisite();
	}

	/**
	 * Wire up admin notices so users understand why the plugin is doing nothing.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'render_notice' ] );
	}

	/**
	 * Render the "this plugin requires multisite" admin notice.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Cross Site Media requires WordPress Multisite. The plugin is loaded but inactive on this install.',
				'cross-site-media'
			)
		);
	}
}

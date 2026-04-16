<?php
/**
 * Environment guard.
 * Confirms the installed environment meets the minimum plugin requirements.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Environment guard.
 * This module only runs when the plugin is activated on a non-supported environment.
 */
class Environment implements ModuleInterface {
	use Module;

	/**
	 * The minimum version of WordPress required for the plugin to work.
	 */
	public const MIN_WORDPRESS_VERSION = '6.7';

	/**
	 * The minimum version of PHP required for the plugin to work.
	 */
	public const MIN_PHP_VERSION = '8.2';

	/**
	 * Register only when viewing admin screens on a non-supported environment.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_admin() && ( ! is_multisite() || ! $this->check_wp_version() || ! $this->check_php_version() );
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
	 * Checks if the WordPress version meets the minimum requirement.
	 *
	 * @return bool True if WordPress version is sufficient, false otherwise.
	 */
	public function check_wp_version(): bool {
		return is_wp_version_compatible( self::MIN_WORDPRESS_VERSION );
	}

	/**
	 * Checks if the PHP version meets the minimum requirement.
	 *
	 * @return bool True if PHP version is sufficient, false otherwise.
	 */
	public function check_php_version(): bool {
		return is_php_version_compatible( self::MIN_PHP_VERSION );
	}

	/**
	 * Render the admin notice for environment issues.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		$issues = [];

		// Check if multisite is enabled.
		if ( ! is_multisite() ) {
			$issues[] = __( 'Multisite is not enabled.', 'cross-site-media' );
		}

		// Check if WordPress version is compatible.
		if ( ! $this->check_wp_version() ) {
			$issues[] = sprintf(
				// translators: %1$s: The current WordPress version, %2$s: The minimum required WordPress version.
				__( 'WordPress version is not compatible. You are running WordPress %1$s, but Cross Site Media requires WordPress %2$s.', 'cross-site-media' ),
				get_bloginfo( 'version' ),
				self::MIN_WORDPRESS_VERSION
			);
		}

		// Check if PHP version is compatible.
		if ( ! $this->check_php_version() ) {
			$issues[] = sprintf(
				// translators: %1$s: The current PHP version, %2$s: The minimum required PHP version.
				__( 'PHP version is not compatible. You are running PHP %1$s, but Cross Site Media requires PHP %2$s.', 'cross-site-media' ),
				phpversion(),
				self::MIN_PHP_VERSION
			);
		}

		if ( ! empty( $issues ) ) {
			$message = sprintf(
				// translators: %s: A list of the issues with the environment.
				__( 'There are issues with your environment preventing Cross Site Media from working: %s', 'cross-site-media' ),
				esc_html( "\n\n" . implode( "\n", $issues ) )
			);

			wp_admin_notice( $message, [ 'type' => 'error' ] );
		}
	}
}

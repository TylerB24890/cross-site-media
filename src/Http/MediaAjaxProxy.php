<?php
/**
 * admin-ajax proxy that switches blogs for core media actions when requested.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Http;

use CrossSiteMedia\Support\AccessControl;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * The "switch before core runs" trick borrowed from humanmade/network-media-library.
 *
 * For each media action core registers under wp_ajax_*, we hook at priority 0 to read
 * `cross_site_blog_id` off the request, validate the user can access that blog, and
 * `switch_to_blog()` so the default core handler queries / mutates the right database.
 *
 * We restore the original blog on `shutdown` so any post-action hooks run in the
 * expected context. Core's own action handlers terminate via `wp_send_json_*()`, so
 * shutdown is the right place to clean up.
 */
class MediaAjaxProxy implements ModuleInterface {
	use Module;

	/**
	 * Core actions whose handlers query or mutate the media library.
	 *
	 * Mirrors the list NML hooks; we keep them all so behavior parity with
	 * "browsing another site's library natively" stays tight.
	 */
	private const PROXIED_ACTIONS = [
		'query-attachments',
		'get-attachment',
		'save-attachment',
		'save-attachment-compat',
		'send-attachment-to-editor',
		'image-editor',
		'imgedit-preview',
		'crop-image',
	];

	/**
	 * Tracks how many switches we've performed so we can restore in pairs.
	 *
	 * @var int
	 */
	private int $switches = 0;

	/**
	 * Register only on multisite admin/AJAX requests.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite() && ( wp_doing_ajax() || is_admin() );
	}

	/**
	 * Hook every proxied core AJAX action at priority 0 so we can switch before core runs.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( self::PROXIED_ACTIONS as $action ) {
			add_action( "wp_ajax_{$action}", [ $this, 'maybe_switch' ], 0 );
		}

		// `set-attachment-thumbnail` (used by media frame) lives behind a different action name.
		add_action( 'wp_ajax_set-attachment-thumbnail', [ $this, 'maybe_switch' ], 0 );

		add_action( 'shutdown', [ $this, 'restore_all' ], 0 );
	}

	/**
	 * Inspect the incoming AJAX request and switch if a valid blog id is present.
	 */
	public function maybe_switch(): void {
		$blog_id = AccessControl::requested_blog_id();
		if ( ! $blog_id ) {
			return;
		}

		if ( ! AccessControl::user_can_browse( $blog_id ) ) {
			wp_send_json_error(
				[
					'code'    => 'cross_site_media_forbidden',
					'message' => __( 'You do not have permission to access that subsite\'s media.', 'cross-site-media' ),
				],
				403
			);
		}

		switch_to_blog( $blog_id );
		++$this->switches;
	}

	/**
	 * Pair every switch_to_blog from this request with a restore_current_blog so we
	 * leave the global blog stack as we found it.
	 */
	public function restore_all(): void {
		while ( $this->switches > 0 ) {
			restore_current_blog();
			--$this->switches;
		}
	}
}

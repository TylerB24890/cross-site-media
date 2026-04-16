<?php
/**
 * Subsite filter for the Media Library list-mode screen.
 * Adds a select element to the Media Library list-mode to navigate between subsites.
 *
 * Grid mode is handled by `MediaAssets` + the `MediaFrame.Manage` JS extension;
 * List mode is plain HTML, so we need a server-side path here.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Admin;

use CrossSiteMedia\Support\AccessControl;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * ListModeFilter class.
 */
class ListModeFilter implements ModuleInterface {
	use Module;

	/**
	 * Whether we've performed a switch_to_blog this request.
	 *
	 * @var bool
	 */
	private bool $switched = false;

	/**
	 * Blog id the request originally landed on, captured before we switch.
	 *
	 * @var int
	 */
	private int $original_blog_id = 0;

	/**
	 * Register only on multisite admin requests.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite() && is_admin();
	}

	/**
	 * Hook into WP.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'load-upload.php', [ $this, 'maybe_switch' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_filter' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'maybe_restore' ], 0 );
	}

	/**
	 * Switch to the requested blog before the list table is built.
	 *
	 * @return void
	 */
	public function maybe_switch(): void {
		if ( ! $this->is_list_mode_request() ) {
			return;
		}

		$blog_id = AccessControl::requested_blog_id();
		if ( ! $blog_id || ! AccessControl::user_can_browse( $blog_id ) ) {
			return;
		}

		$this->original_blog_id = get_current_blog_id();
		switch_to_blog( $blog_id );
		$this->switched = true;
	}

	/**
	 * Restore the original blog after the request.
	 *
	 * @return void
	 */
	public function maybe_restore(): void {
		if ( $this->switched ) {
			restore_current_blog();
			$this->switched = false;
		}
	}

	/**
	 * Render the subsite select inside the Media list filter bar.
	 *
	 * @param string $post_type Current list-table post type.
	 * @param string $which     'top' | 'bottom' | 'bar'.
	 *
	 * @return void
	 */
	public function render_filter( $post_type, $which = 'top' ): void {
		if ( 'attachment' !== $post_type || 'bar' !== $which ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// Pass the original (pre-switch) blog id so the user can see what they just selected.
		$exclude  = $this->switched ? $this->original_blog_id : null;
		$subsites = AccessControl::accessible_subsites( $exclude );
		if ( empty( $subsites ) ) {
			return;
		}

		// Selected blog reflects either the active switch or any pending request param.
		$selected = $this->switched ? get_current_blog_id() : AccessControl::requested_blog_id();

		printf(
			'<label for="cross-site-media-filter-list" class="screen-reader-text">%s</label>',
			esc_html__( 'Show media from', 'cross-site-media' )
		);
		printf(
			'<select id="cross-site-media-filter-list" name="%s">',
			esc_attr( AccessControl::BLOG_ID_PARAM )
		);
		printf(
			'<option value="">%s</option>',
			esc_html__( 'Current site', 'cross-site-media' )
		);

		// Render the select options for each subsite.
		foreach ( $subsites as $site ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $site['blog_id'],
				selected( $selected, (int) $site['blog_id'], false ),
				esc_html( $site['name'] )
			);
		}
		echo '</select>';
	}

	/**
	 * Check if we're in list-mode on the Media Library screen.
	 *
	 * @return bool
	 */
	private function is_list_mode_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode_param = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		if ( '' !== $mode_param ) {
			return 'list' === $mode_param;
		}

		// No param → fall back to the user's persisted preference. Default is grid.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$pref = get_user_option( 'media_library_mode', $user_id );
		return 'list' === $pref;
	}
}

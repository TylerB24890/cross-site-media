<?php
/**
 * Subsite filter for the Media Library list-mode screen.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Admin;

use CrossSiteMedia\Support\AccessControl;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Adds a "Show media from" <select> to upload.php's list-mode filter bar and
 * — when a subsite is selected — switches to that blog early enough that the
 * `WP_Media_List_Table` query runs against it.
 *
 * Grid mode is handled by `MediaAssets` + the `MediaFrame.Manage` JS extension;
 * list mode is plain WP-rendered HTML, so we need a server-side path here.
 *
 * Trade-off: switching the blog during `load-upload.php` means the admin bar
 * and a few admin chrome details reflect the source site for the rest of the
 * request. That's intentional — attachment edit links auto-route to the
 * source site's `post.php?post=…` so users can act on the items they see.
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
	 * Used to exclude the *original* site from the subsite dropdown after the
	 * switch (otherwise we'd hide the site the user just selected, since it
	 * becomes `get_current_blog_id()` post-switch).
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
	 * Hook the upload.php loader, the list-table filter slot, and shutdown
	 * cleanup.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'load-upload.php', [ $this, 'maybe_switch' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_filter' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'maybe_restore' ], 0 );
	}

	/**
	 * If we're loading upload.php in list mode and a valid subsite was
	 * requested, switch to that blog before the list table is built.
	 *
	 * @return void
	 */
	public function maybe_switch(): void {
		if ( ! $this->is_list_mode_request() ) {
			return;
		}

		$blog_id = AccessControl::requested_blog_id();
		if ( ! $blog_id ) {
			return;
		}

		if ( ! AccessControl::user_can_browse( $blog_id ) ) {
			return;
		}

		$this->original_blog_id = get_current_blog_id();
		switch_to_blog( $blog_id );
		$this->switched = true;
	}

	/**
	 * Pair the `switch_to_blog` from `maybe_switch` with a restore at the end
	 * of the request.
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
	 * Render the subsite <select> inside the Media list table's filter bar.
	 *
	 * `restrict_manage_posts` fires on every list-table screen; we scope to
	 * post_type=attachment + the upload screen so we don't pollute Posts, etc.
	 *
	 * @param string $post_type Current list-table post type.
	 * @param string $which     'top' | 'bottom' | 'bar' (Media uses 'bar').
	 *
	 * @return void
	 */
	public function render_filter( $post_type, $which = 'top' ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		if ( 'bar' !== $which ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// Pass the original (pre-switch) blog id so the now-current blog
		// stays in the dropdown — otherwise the user can't see what they
		// just selected.
		$exclude  = $this->switched ? $this->original_blog_id : null;
		$subsites = AccessControl::accessible_subsites( $exclude );
		if ( empty( $subsites ) ) {
			return;
		}

		// Selected blog reflects either the active switch (so the dropdown
		// stays in sync after submit) or any pending request param.
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
			esc_html__( 'This site', 'cross-site-media' )
		);
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
	 * Are we on upload.php with `mode=list` (or has the user persisted list
	 * mode as their preference)?
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

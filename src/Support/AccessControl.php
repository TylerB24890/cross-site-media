<?php
/**
 * Capability checks for cross-site media access.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Support;

/**
 * Centralizes the question "is the current user allowed to interact with media on blog X?".
 *
 * The plugin's core requirement: a user only sees a subsite's media tab if they could open
 * that subsite's Media Library directly. We honor that by gating every blog switch on
 * `upload_files` against the target blog. Editing remote metadata adds an `edit_post`
 * check on the specific attachment.
 */
class AccessControl {

	/**
	 * Parameter name read off the request to identify the target blog.
	 */
	public const BLOG_ID_PARAM = 'cross_site_blog_id';

	/**
	 * May the current user browse blog $blog_id's media library?
	 *
	 * @param int $blog_id Target blog ID.
	 */
	public static function user_can_browse( int $blog_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || $blog_id <= 0 ) {
			return false;
		}

		// Super admins always pass.
		if ( is_super_admin( $user_id ) ) {
			return true;
		}

		// `user_can_for_site()` (WP 6.7+) handles its own switch_to_blog and is the
		// canonical way to check capabilities on another site in a multisite network.
		return user_can_for_site( $user_id, $blog_id, 'upload_files' );
	}

	/**
	 * May the current user edit attachment $attachment_id on blog $blog_id?
	 *
	 * @param int $blog_id       Target blog ID.
	 * @param int $attachment_id Attachment post ID on that blog.
	 */
	public static function user_can_edit_attachment( int $blog_id, int $attachment_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || $blog_id <= 0 || $attachment_id <= 0 ) {
			return false;
		}

		return user_can_for_site( $user_id, $blog_id, 'edit_post', $attachment_id );
	}

	/**
	 * Pull the cross-site blog id off the current request, normalized.
	 *
	 * Returns 0 when absent or invalid — callers should treat that as "no switch".
	 *
	 * @param \WP_REST_Request|null $request Optional REST request to read from.
	 */
	public static function requested_blog_id( ?\WP_REST_Request $request = null ): int {
		if ( $request instanceof \WP_REST_Request ) {
			$value = $request->get_param( self::BLOG_ID_PARAM );
		} else {
			// admin-ajax actions use $_REQUEST. Nonce is verified by the surrounding action handler.
			// `wp.media.model.Attachments.sync` nests extra props under a `query` sub-key
			// before sending to `wp_ajax_query-attachments`, so we accept both shapes.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$top_level = isset( $_REQUEST[ self::BLOG_ID_PARAM ] )
				? sanitize_text_field( wp_unslash( $_REQUEST[ self::BLOG_ID_PARAM ] ) )
				: null;

			$nested = null;
			if ( isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && isset( $_REQUEST['query'][ self::BLOG_ID_PARAM ] ) ) {
				$nested = sanitize_text_field( wp_unslash( $_REQUEST['query'][ self::BLOG_ID_PARAM ] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$value = $top_level ?? $nested;
		}

		$blog_id = is_scalar( $value ) ? (int) $value : 0;
		if ( $blog_id <= 0 ) {
			return 0;
		}

		// Reject requests targeting the current blog — there's nothing to switch.
		if ( get_current_blog_id() === $blog_id ) {
			return 0;
		}

		// Validate the blog actually exists.
		if ( ! get_site( $blog_id ) ) {
			return 0;
		}

		return $blog_id;
	}
}

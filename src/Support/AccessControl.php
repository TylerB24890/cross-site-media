<?php
/**
 * Capability checks for cross-site media access.
 * Determines if the current user has the necessary capabilities to interact with media on a given blog.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Support;

use WP_REST_Request;

/**
 * AccessControl class.
 * Handles capability checks for cross-site media access.
 */
class AccessControl {

	/**
	 * Target blog ID parameter name for the request.
	 */
	public const BLOG_ID_PARAM = 'cross_site_blog_id';

	/**
	 * Can the current user browse the requested blog's media library?
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

		$user_can = user_can_for_site( $user_id, $blog_id, 'upload_files' );

		/**
		 * Filters whether the current user can browse the requested blog's media library.
		 *
		 * @param bool $user_can Whether the current user can browse the requested blog's media library.
		 * @param int  $blog_id  The ID of the target blog.
		 * @param int  $user_id  The ID of the current user.
		 *
		 * @return bool
		 */
		return apply_filters( 'cross_site_media_user_can_browse', $user_can, $blog_id, $user_id );
	}

	/**
	 * Can the current user edit the requested attachment on the requested blog?
	 *
	 * @param int $blog_id       Target blog ID.
	 * @param int $attachment_id Attachment post ID on that blog.
	 */
	public static function user_can_edit( int $blog_id, int $attachment_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || $blog_id <= 0 || $attachment_id <= 0 ) {
			return false;
		}

		$user_can = user_can_for_site( $user_id, $blog_id, 'edit_post', $attachment_id );

		/**
		 * Filters whether the current user can edit the requested attachment on the requested blog.
		 *
		 * @param bool $user_can Whether the current user can edit the requested attachment on the requested blog.
		 * @param int  $blog_id       The ID of the target blog.
		 * @param int  $attachment_id The ID of the attachment.
		 * @param int  $user_id       The ID of the current user.
		 *
		 * @return bool
		 */
		return apply_filters( 'cross_site_media_user_can_edit', $user_can, $blog_id, $attachment_id, $user_id );
	}

	/**
	 * List of subsites the current user can browse, excluding the given blog.
	 *
	 * @param int|null $exclude_blog_id Blog id to omit from the result. Defaults to
	 *                                  `get_current_blog_id()` when null.
	 *
	 * @return array<int, array{blog_id:int, name:string, path:string}>
	 */
	public static function accessible_subsites( ?int $exclude_blog_id = null ): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$exclude  = $exclude_blog_id ?? get_current_blog_id();
		$subsites = [];

		foreach ( get_blogs_of_user( $user_id ) as $site ) {
			$blog_id = (int) $site->userblog_id;
			if ( $blog_id === $exclude ) {
				continue;
			}

			if ( ! self::user_can_browse( $blog_id ) ) {
				continue;
			}

			$subsites[] = [
				'blog_id' => $blog_id,
				'name'    => (string) $site->blogname,
				'path'    => (string) $site->path,
			];
		}

		return $subsites;
	}

	/**
	 * Extract the target blog ID from the current request.
	 *
	 * @param WP_REST_Request|null $request Optional REST request to read from.
	 *
	 * @return int
	 */
	public static function requested_blog_id( ?WP_REST_Request $request = null ): int {
		if ( $request instanceof WP_REST_Request ) {
			$value = $request->get_param( self::BLOG_ID_PARAM );
		} else {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$top_level = isset( $_REQUEST[ self::BLOG_ID_PARAM ] ) && is_string( $_REQUEST[ self::BLOG_ID_PARAM ] )
				? sanitize_text_field( wp_unslash( $_REQUEST[ self::BLOG_ID_PARAM ] ) )
				: null;

			$nested = null;

			// wp.media nests extra props under a `query` sub-key.
			if (
				isset( $_REQUEST['query'] ) &&
				is_array( $_REQUEST['query'] ) &&
				isset( $_REQUEST['query'][ self::BLOG_ID_PARAM ] )
			) {
				$nested = is_string( $_REQUEST['query'][ self::BLOG_ID_PARAM ] )
					? sanitize_text_field( wp_unslash( $_REQUEST['query'][ self::BLOG_ID_PARAM ] ) )
					: null;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$value = $top_level ?? $nested;
		}

		$blog_id = is_scalar( $value ) ? (int) $value : 0;
		if ( $blog_id <= 0 ) {
			return 0;
		}

		// Reject requests targeting the current blog — there's nothing to switch.
		// Reject requests targeting a non-existent blog.
		if (
			get_current_blog_id() === $blog_id ||
			! get_site( $blog_id )
		) {
			return 0;
		}

		return $blog_id;
	}
}

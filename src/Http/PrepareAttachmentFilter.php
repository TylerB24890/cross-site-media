<?php
/**
 * Decorates attachment-for-JS payloads when we're serving from a switched blog.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Http;

use CrossSiteMedia\Support\AccessControl;
use CrossSiteMedia\Support\Sideloader;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * When an attachment is being prepared for a media-frame response and we are currently
 * inside a switch_to_blog() (because MediaAjaxProxy or RestMediaProxy switched us),
 * tag the response with origin metadata. The JS side reads these flags to:
 *
 *   - decide whether to enable / disable the details-pane edit fields,
 *   - know which (blog_id, attachment_id) pair to pass to the sideload endpoint
 *     when the user actually selects/inserts the item.
 */
class PrepareAttachmentFilter implements ModuleInterface {
	use Module;

	/**
	 * Register on any multisite request.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite();
	}

	/**
	 * Hook the attachment-for-JS filter.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'decorate' ], 100, 2 );
	}

	/**
	 * Add crossSiteMedia origin metadata when serving an attachment from a switched blog.
	 *
	 * @param array<string,mixed> $response   Prepared response.
	 * @param \WP_Post            $attachment Attachment post (in current — possibly switched — context).
	 *
	 * @return array<string,mixed>
	 */
	public function decorate( array $response, \WP_Post $attachment ): array {
		// Only decorate when we are operating against a switched-to blog.
		if ( ! ms_is_switched() ) {
			return $response;
		}

		$blog_id = (int) get_current_blog_id();
		$site    = get_site( $blog_id );

		$response['crossSiteMedia'] = [
			'sourceBlogId'    => $blog_id,
			'sourceAttachId'  => (int) $attachment->ID,
			'sourceBlogName'  => $site ? get_blog_option( $blog_id, 'blogname' ) : '',
			'sourceBlogPath'  => $site ? $site->path : '',
			'canEditOnOrigin' => current_user_can( 'edit_post', $attachment->ID ),
		];

		// Discourage the JS frontend from offering destructive actions against remote items.
		// Sideloading produces a fresh local attachment for inserts; deletes/permanent edits
		// against remote items would require explicit cap checks we don't grant by default.
		unset( $response['nonces']['delete'] );

		return $response;
	}
}

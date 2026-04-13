<?php
/**
 * REST endpoint: copy a remote subsite attachment into the current site.
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
 * POST /wp-json/cross-site-media/v1/sideload
 *
 * Body: { source_blog_id: int, source_attachment_id: int }
 *
 * Returns the local attachment in the same shape `wp_prepare_attachment_for_js`
 * produces, so callers can drop it straight into the media frame.
 */
class SideloadController implements ModuleInterface {
	use Module;

	public const REST_NAMESPACE = 'cross-site-media/v1';
	public const REST_ROUTE     = '/sideload';

	/**
	 * Register on any multisite request.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite();
	}

	/**
	 * Register the REST route on `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the POST /sideload endpoint.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => [ $this, 'check_permission' ],
				'callback'            => [ $this, 'handle' ],
				'args'                => [
					'source_blog_id'       => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'source_attachment_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Permission gate: the user must (a) be logged in, (b) be allowed to upload to the
	 * current site (since we're creating an attachment here), and (c) be allowed to
	 * browse the source blog.
	 *
	 * @param \WP_REST_Request $request Request carrying `source_blog_id`.
	 *
	 * @return bool
	 */
	public function check_permission( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$source_blog_id = (int) $request->get_param( 'source_blog_id' );
		if ( $source_blog_id <= 0 ) {
			return false;
		}

		return AccessControl::user_can_browse( $source_blog_id );
	}

	/**
	 * Handle the sideload request: copy the remote attachment and return it in
	 * media-frame shape so callers can drop it straight into the media modal.
	 *
	 * @param \WP_REST_Request $request Request carrying `source_blog_id` and `source_attachment_id`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$source_blog_id       = (int) $request->get_param( 'source_blog_id' );
		$source_attachment_id = (int) $request->get_param( 'source_attachment_id' );

		$sideloader = new Sideloader();
		$result     = $sideloader->sideload( $source_blog_id, $source_attachment_id );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
			if ( $status <= 0 ) {
				$status = 500;
			}
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => $status ] );
		}

		// Return the local attachment in media-frame shape.
		$post = get_post( $result );
		if ( ! $post ) {
			return new \WP_Error( 'cross_site_media_post_missing', __( 'Sideloaded attachment could not be loaded.', 'cross-site-media' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( wp_prepare_attachment_for_js( $post ) );
	}
}

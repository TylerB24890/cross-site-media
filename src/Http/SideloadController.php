<?php
/**
 * REST endpoint to sideload a remote attachment into the current site.
 * POST /wp-json/cross-site-media/v1/sideload
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Http;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use CrossSiteMedia\Support\AccessControl;
use CrossSiteMedia\Support\Sideloader;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * SideloadController class.
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
				'methods'             => WP_REST_Server::CREATABLE,
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
	 * Check user permissions for the sideload request.
	 *
	 * The user must:
	 * (a) be logged in,
	 * (b) be allowed to upload to the current site,
	 * (c) be allowed to browse the source blog.
	 *
	 * @param WP_REST_Request $request The sideload request.
	 *
	 * @return bool
	 */
	public function check_permission( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$source_blog_id = $request->get_param( 'source_blog_id' );
		if ( ! is_numeric( $source_blog_id ) || (int) $source_blog_id <= 0 ) {
			return false;
		}

		return AccessControl::user_can_browse( (int) $source_blog_id );
	}

	/**
	 * Handle the sideload request.
	 * Copy the remote attachment and return it prepared for the media frame.
	 *
	 * @param WP_REST_Request $request The sideload request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$source_blog_id       = $request->get_param( 'source_blog_id' );
		$source_attachment_id = $request->get_param( 'source_attachment_id' );

		if ( ! is_numeric( $source_attachment_id ) || (int) $source_attachment_id <= 0 ) {
			return new WP_Error(
				'cross_site_media_invalid_args',
				__( 'Invalid attachment id.', 'cross-site-media' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! is_numeric( $source_blog_id ) || (int) $source_blog_id <= 0 ) {
			return new WP_Error(
				'cross_site_media_invalid_args',
				__( 'Invalid blog id.', 'cross-site-media' ),
				[ 'status' => 400 ]
			);
		}

		$result = ( new Sideloader() )->sideload( (int) $source_blog_id, (int) $source_attachment_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $result );
		if ( ! $post ) {
			return new WP_Error(
				'cross_site_media_post_missing',
				__( 'Sideloaded attachment could not be loaded.', 'cross-site-media' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( wp_prepare_attachment_for_js( $post ) );
	}

	/**
	 * Get the REST endpoint for the sideload controller.
	 *
	 * @return string The REST endpoint path.
	 */
	public static function get_endpoint(): string {
		return untrailingslashit( self::REST_NAMESPACE ) . self::REST_ROUTE;
	}
}

<?php
/**
 * REST proxy to switch blogs for the /wp/v2/media endpoint when requested.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Http;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_HTTP_Response;
use CrossSiteMedia\Support\AccessControl;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * RestMediaProxy class.
 * Handles blog switching for the /wp/v2/media endpoint when requested.
 */
class RestMediaProxy implements ModuleInterface {
	use Module;

	/**
	 * Route prefixes we proxy. Any request whose route starts with one of these
	 * is eligible for switching when the cross_site_blog_id param is present.
	 */
	private const PROXIED_ROUTES = [
		'/wp/v2/media',
	];

	/**
	 * Whether we've switched the blog for the current request and still owe a restore.
	 *
	 * @var bool
	 */
	private bool $switched = false;

	/**
	 * Register on any multisite request.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite();
	}

	/**
	 * Register the REST dispatch filters.
	 * These filters run before and after the route handler to switch to and restore the blog.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', [ $this, 'maybe_switch' ], 0, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'maybe_restore' ], PHP_INT_MAX );
	}

	/**
	 * Switch to the requested blog before the route handler runs.
	 *
	 * @param mixed           $result  The existing short-circuit result from earlier filters.
	 * @param WP_REST_Server  $server  The server instance.
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return mixed
	 */
	public function maybe_switch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( null !== $result ) {
			// Another filter already short-circuited; don't switch.
			return $result;
		}

		// Make sure this is a route we can proxy.
		if ( ! $this->is_proxied_route( $request->get_route() ) ) {
			return $result;
		}

		// Get the target blog ID from the request.
		$blog_id = AccessControl::requested_blog_id( $request );
		if ( ! $blog_id ) {
			return $result;
		}

		// Make sure the user has proper access.
		if ( ! AccessControl::user_can_browse( $blog_id ) ) {
			return new WP_Error(
				'cross_site_media_forbidden',
				__( 'You do not have permission to access that subsite\'s media.', 'cross-site-media' ),
				[ 'status' => 403 ]
			);
		}

		// The `post` argument ties an attachment to a parent post.
		// The parent post will not exist on the target blog, so we need to clear it.
		$request->set_param( 'post', null );

		switch_to_blog( $blog_id );
		$this->switched = true;

		return $result;
	}

	/**
	 * Restore the original blog after the route handler has returned.
	 *
	 * @param WP_HTTP_Response $response Response object.
	 *
	 * @return WP_HTTP_Response
	 */
	public function maybe_restore( $response ) {
		if ( $this->switched ) {
			restore_current_blog();
			$this->switched = false;
		}
		return $response;
	}

	/**
	 * Is the given REST route one we proxy?
	 *
	 * @param string $route Route path, e.g. `/wp/v2/media/42`.
	 *
	 * @return bool
	 */
	private function is_proxied_route( string $route ): bool {
		foreach ( self::PROXIED_ROUTES as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}

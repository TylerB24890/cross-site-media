<?php
/**
 * REST proxy that switches blogs for /wp/v2/media when requested.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Http;

use CrossSiteMedia\Support\AccessControl;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Block-editor (and any modern client) talks to the media library via /wp/v2/media,
 * not admin-ajax. We need the same "switch before core runs" treatment there.
 *
 * `rest_pre_dispatch` fires before the route handler resolves the post — we switch
 * there. `rest_post_dispatch` runs after the response is built — we restore there.
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
	 * Wire up REST dispatch filters that sandwich the route handler with switch/restore.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', [ $this, 'maybe_switch' ], 0, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'maybe_restore' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Switch to the requested blog before the media route handler runs.
	 *
	 * @param mixed            $result  Existing short-circuit result from earlier filters.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return mixed
	 */
	public function maybe_switch( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( null !== $result ) {
			// Another filter already short-circuited; don't switch.
			return $result;
		}

		if ( ! $this->is_proxied_route( $request->get_route() ) ) {
			return $result;
		}

		$blog_id = AccessControl::requested_blog_id( $request );
		if ( ! $blog_id ) {
			return $result;
		}

		if ( ! AccessControl::user_can_browse( $blog_id ) ) {
			return new \WP_Error(
				'cross_site_media_forbidden',
				__( 'You do not have permission to access that subsite\'s media.', 'cross-site-media' ),
				[ 'status' => 403 ]
			);
		}

		// `post` arg ties an attachment to a parent post on the current site; clearing it
		// avoids cross-blog id collisions when listing.
		$request->set_param( 'post', null );

		switch_to_blog( $blog_id );
		$this->switched = true;

		return $result;
	}

	/**
	 * Restore the original blog after the route handler has returned.
	 *
	 * @param \WP_HTTP_Response $response Response object.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request object.
	 *
	 * @return \WP_HTTP_Response
	 */
	public function maybe_restore( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
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

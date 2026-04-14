<?php
/**
 * Enqueue + localize the admin-side assets.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Admin;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;
use TenupFramework\Assets\GetAssetInfo;
use CrossSiteMedia\Support\AccessControl;
use CrossSiteMedia\Http\SideloadController;

/**
 * MediaAssets class.
 */
class MediaAssets implements ModuleInterface {
	use Module;
	use GetAssetInfo;

	/**
	 * Script/style handle used for registration + localization.
	 */
	public const HANDLE = 'cross-site-media-admin';

	/**
	 * Window global the bundle reads its config from.
	 */
	public const DATA_GLOBAL = 'CrossSiteMedia';

	/**
	 * Register only on multisite admin requests.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite() && is_admin();
	}

	/**
	 * Hook into admin_enqueue_scripts.
	 *
	 * @return void
	 */
	public function register() {
		$this->setup_asset_vars(
			dist_path: CROSS_SITE_MEDIA_DIST_PATH,
			fallback_version: CROSS_SITE_MEDIA_VERSION
		);

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue + localize the admin assets.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		// Only enqueue if the user has the ability to upload files on the current site.
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$subsites = \CrossSiteMedia\Support\AccessControl::accessible_subsites();
		if ( empty( $subsites ) ) {
			// User is only a member of one site — nothing to show.
			return;
		}

		/**
		 * Script dependencies.
		 *
		 * @var array<string> $dependencies
		 */
		$dependencies = $this->get_asset_info( 'admin', 'dependencies' );
		$dependencies = array_unique(
			array_merge(
				$dependencies,
				[
					'media-views',
					'media-editor',
					'wp-i18n',
				]
			)
		);

		wp_enqueue_script(
			self::HANDLE,
			CROSS_SITE_MEDIA_DIST_URL . 'js/admin.js',
			$dependencies,
			$this->get_asset_info( 'admin', 'version' ),
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			CROSS_SITE_MEDIA_DIST_URL . 'css/admin.css',
			[],
			$this->get_asset_info( 'admin', 'version' )
		);

		wp_localize_script(
			self::HANDLE,
			self::DATA_GLOBAL,
			$this->build_localized_data( $subsites )
		);
	}

	/**
	 * Shape the data blob read by the JS bundle on boot.
	 *
	 * @param array $subsites Subsites list.
	 *
	 * @return array
	 */
	private function build_localized_data( array $subsites ): array {
		return [
			'subsites'      => $subsites,
			'currentBlogId' => (int) get_current_blog_id(),
			'rest'          => [
				'root'          => esc_url_raw( rest_url() ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'sideloadRoute' => SideloadController::get_endpoint(),
				'blogIdParam'   => AccessControl::BLOG_ID_PARAM,
			],
			'strings'       => [
				'routerHeading'  => __( 'Subsites', 'cross-site-media' ),
				'sideloading'    => __( 'Copying from subsite…', 'cross-site-media' ),
				'sideloadFail'   => __( 'Could not copy the selected item from the subsite.', 'cross-site-media' ),
				'badge'          => /* translators: %s: subsite name */ __( 'From %s', 'cross-site-media' ),
				'manageLabel'    => __( 'Show media from', 'cross-site-media' ),
				'manageThisSite' => __( 'Current site', 'cross-site-media' ),
			],
		];
	}
}

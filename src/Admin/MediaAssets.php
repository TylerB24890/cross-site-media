<?php
/**
 * Enqueue + localize the admin-side assets that extend wp.media with subsite tabs.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Admin;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Attaches the compiled admin bundle to any admin page that also loads `wp.media`,
 * plus to the standalone Media Library screen. The bundle is empty on pages without
 * media (script-loader dependency on `media-views` handles that automatically).
 */
class MediaAssets implements ModuleInterface {
	use Module;

	/**
	 * Script/style handle used for registration + localization.
	 */
	public const HANDLE = 'cross-site-media-admin';

	/**
	 * Window global the bundle reads its config from.
	 */
	public const DATA_GLOBAL = 'CrossSiteMedia';

	/**
	 * Register only on multisite admin requests. Modals can also load on the front end
	 * via Gutenberg in some flows, but for v1 we scope this to wp-admin to keep the
	 * surface area manageable.
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_multisite() && is_admin();
	}

	/**
	 * Hook into `admin_enqueue_scripts` so we can enqueue alongside `media-views`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue + localize the admin bundle.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$subsites = $this->accessible_subsites_for_current_user();
		if ( empty( $subsites ) ) {
			// User is only a member of one site — nothing to show.
			return;
		}

		$asset_file = CROSS_SITE_MEDIA_DIST_PATH . 'js/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => CROSS_SITE_MEDIA_VERSION,
			];

		$dependencies = array_unique(
			array_merge(
				$asset['dependencies'],
				[
					'media-views',
					'media-editor',
					'wp-api-fetch',
					'wp-i18n',
				]
			)
		);

		wp_enqueue_script(
			self::HANDLE,
			CROSS_SITE_MEDIA_DIST_URL . 'js/admin.js',
			$dependencies,
			$asset['version'],
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			CROSS_SITE_MEDIA_DIST_URL . 'css/admin.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			self::HANDLE,
			self::DATA_GLOBAL,
			$this->build_localized_data( $subsites )
		);
	}

	/**
	 * Build the list of subsites the current user can browse, excluding the current blog.
	 *
	 * @return array<int, array{blog_id:int, name:string, path:string}>
	 */
	private function accessible_subsites_for_current_user(): array {
		$user_id      = get_current_user_id();
		$current_blog = get_current_blog_id();
		$user_sites   = get_blogs_of_user( $user_id );

		$result = [];
		foreach ( $user_sites as $site ) {
			$blog_id = (int) $site->userblog_id;
			if ( $blog_id === $current_blog ) {
				continue;
			}

			if ( ! \CrossSiteMedia\Support\AccessControl::user_can_browse( $blog_id ) ) {
				continue;
			}

			$result[] = [
				'blog_id' => $blog_id,
				'name'    => (string) $site->blogname,
				'path'    => (string) $site->path,
			];
		}

		return $result;
	}

	/**
	 * Shape the data blob read by the JS bundle on boot.
	 *
	 * @param array<int, array{blog_id:int, name:string, path:string}> $subsites Subsites list.
	 *
	 * @return array<string, mixed>
	 */
	private function build_localized_data( array $subsites ): array {
		return [
			'subsites'      => $subsites,
			'currentBlogId' => (int) get_current_blog_id(),
			'rest'          => [
				'root'          => esc_url_raw( rest_url() ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'sideloadRoute' => 'cross-site-media/v1/sideload',
				'blogIdParam'   => \CrossSiteMedia\Support\AccessControl::BLOG_ID_PARAM,
			],
			'strings'       => [
				'routerHeading'  => __( 'Subsites', 'cross-site-media' ),
				'sideloading'    => __( 'Copying from subsite…', 'cross-site-media' ),
				'sideloadFail'   => __( 'Could not copy the selected item from the subsite.', 'cross-site-media' ),
				'badge'          => /* translators: %s: subsite name */ __( 'From %s', 'cross-site-media' ),
				'manageLabel'    => __( 'Show media from', 'cross-site-media' ),
				'manageThisSite' => __( 'This site', 'cross-site-media' ),
			],
		];
	}
}

<?php
/**
 * Copies an attachment from a remote subsite into the current site.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Support;

/**
 * Sideloads a single attachment, recording its origin so future selections of the same
 * remote attachment reuse the local copy instead of re-downloading.
 *
 * Why sideload at all: in the cross-site-media flow, every selection in the media modal
 * should resolve to a real local attachment ID so featured images, gallery blocks, and
 * any other consumer that requires `post_id` keep working unmodified.
 */
class Sideloader {

	/**
	 * Postmeta keys recorded on every sideloaded attachment so we can find it again.
	 */
	public const META_SOURCE_BLOG = '_cross_site_media_source_blog_id';
	public const META_SOURCE_POST = '_cross_site_media_source_attachment_id';

	/**
	 * Sideload a remote attachment into the current site.
	 *
	 * @param int $source_blog_id    Blog to read from.
	 * @param int $source_attachment Attachment post ID on the source blog.
	 *
	 * @return int|\WP_Error Local attachment ID, or WP_Error on failure.
	 */
	public function sideload( int $source_blog_id, int $source_attachment ) {
		if ( $source_blog_id <= 0 || $source_attachment <= 0 ) {
			return new \WP_Error( 'cross_site_media_invalid_args', __( 'Invalid blog or attachment id.', 'cross-site-media' ) );
		}

		// Dedupe before doing any work.
		$existing = $this->find_existing_local_copy( $source_blog_id, $source_attachment );
		if ( $existing ) {
			return $existing;
		}

		// Pull the source file and meta from the origin blog.
		$source = $this->read_source( $source_blog_id, $source_attachment );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Copy the source file into a tmp file under the current site's uploads.
		$tmp = wp_tempnam( $source['filename'] );
		if ( ! $tmp ) {
			return new \WP_Error( 'cross_site_media_tmp_failed', __( 'Unable to allocate temporary file.', 'cross-site-media' ) );
		}

		if ( ! @copy( $source['path'], $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $tmp );
			return new \WP_Error( 'cross_site_media_copy_failed', __( 'Failed to copy source media file.', 'cross-site-media' ) );
		}

		$file_array = [
			'name'     => $source['filename'],
			'tmp_name' => $tmp,
		];

		// Sideload into the current blog. post_data fills in title/caption/description.
		$post_data = [
			'post_title'   => $source['title'],
			'post_content' => $source['description'],
			'post_excerpt' => $source['caption'],
		];

		$attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		// Preserve alt text and stamp origin metadata.
		if ( '' !== $source['alt'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $source['alt'] );
		}
		update_post_meta( $attachment_id, self::META_SOURCE_BLOG, $source_blog_id );
		update_post_meta( $attachment_id, self::META_SOURCE_POST, $source_attachment );

		return (int) $attachment_id;
	}

	/**
	 * Look up a previously-sideloaded local attachment for this source pair.
	 *
	 * @param int $source_blog_id    Blog to read from.
	 * @param int $source_attachment Attachment post ID on the source blog.
	 *
	 * @return int Attachment ID or 0 when no copy exists yet.
	 */
	private function find_existing_local_copy( int $source_blog_id, int $source_attachment ): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => self::META_SOURCE_BLOG,
						'value' => $source_blog_id,
					],
					[
						'key'   => self::META_SOURCE_POST,
						'value' => $source_attachment,
					],
				],
			]
		);

		$ids = $query->posts;
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Read the source attachment under switch_to_blog and return everything we need
	 * to recreate it on the current site.
	 *
	 * @param int $source_blog_id    Blog to read from.
	 * @param int $source_attachment Attachment post ID on the source blog.
	 *
	 * @return array{filename:string,path:string,title:string,caption:string,description:string,alt:string}|\WP_Error
	 */
	private function read_source( int $source_blog_id, int $source_attachment ) {
		switch_to_blog( $source_blog_id );

		$post = get_post( $source_attachment );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			restore_current_blog();
			return new \WP_Error( 'cross_site_media_not_found', __( 'Source attachment not found.', 'cross-site-media' ) );
		}

		$file_path = get_attached_file( $source_attachment, true );
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			restore_current_blog();
			return new \WP_Error( 'cross_site_media_unreadable', __( 'Source media file is not readable.', 'cross-site-media' ) );
		}

		$data = [
			'filename'    => wp_basename( $file_path ),
			'path'        => $file_path,
			'title'       => (string) $post->post_title,
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'alt'         => (string) get_post_meta( $source_attachment, '_wp_attachment_image_alt', true ),
		];

		restore_current_blog();

		return $data;
	}
}

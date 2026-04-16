<?php
/**
 * Copies an attachment from a remote subsite into the current site.
 *
 * @package CrossSiteMedia
 */

declare( strict_types = 1 );

namespace CrossSiteMedia\Support;

use WP_Error;
use WP_Query;

/**
 * Sideload a single attachment from a remote subsite into the current site.
 * This is used for featured images and gallery support requiring a local attachment ID.
 */
class Sideloader {

	/**
	 * Postmeta keys to track the source blog and attachment ID.
	 */
	public const META_SOURCE_BLOG = '_cross_site_media_source_blog_id';
	public const META_SOURCE_POST = '_cross_site_media_source_attachment_id';

	/**
	 * Sideload a remote attachment into the current site.
	 *
	 * @param int $source_blog_id    Blog to read from.
	 * @param int $source_attachment Attachment post ID on the source blog.
	 *
	 * @return int|WP_Error Local attachment ID, or WP_Error on failure.
	 */
	public function sideload( int $source_blog_id, int $source_attachment ): int|WP_Error {
		if ( $source_blog_id <= 0 || $source_attachment <= 0 ) {
			return new WP_Error(
				'cross_site_media_invalid_args',
				__( 'Invalid blog or attachment id.', 'cross-site-media' ),
				[ 'status' => 400 ]
			);
		}

		// Ensure we don't already have a local copy of this attachment.
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
			return new WP_Error(
				'cross_site_media_tmp_failed',
				__( 'Unable to allocate temporary file.', 'cross-site-media' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! @copy( $source['path'], $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $tmp );
			return new WP_Error(
				'cross_site_media_copy_failed',
				__( 'Failed to copy source media file.', 'cross-site-media' ),
				[ 'status' => 500 ]
			);
		}

		$file_array = [
			'name'     => $source['filename'],
			'tmp_name' => $tmp,
		];

		// Sideload into the current blog.
		$post_data = [
			'post_title'   => $source['title'],
			'post_content' => $source['description'],
			'post_excerpt' => $source['caption'],
		];

		/**
		 * Fires before a sideload operation.
		 *
		 * @param array $file_array        The file array.
		 * @param array $post_data         The post data.
		 * @param int   $source_blog_id    The ID of the source blog.
		 * @param int   $source_attachment The ID of the source attachment.
		 */
		do_action( 'cross_site_media_before_sideload', $file_array, $post_data, $source_blog_id, $source_attachment );

		$attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		/**
		 * Filters whether to preserve the origin postmeta when sideloading an attachment.
		 *
		 * @param bool $preserve_meta     Whether to preserve the origin postmeta.
		 * @param int  $source_blog_id    The ID of the source blog.
		 * @param int  $source_attachment The ID of the source attachment.
		 *
		 * @return bool
		 */
		$preserve_meta = apply_filters( 'cross_site_media_preserve_meta', true, $source_blog_id, $source_attachment );

		// Preserve all origin postmeta.
		if ( $preserve_meta && ! empty( $source['postmeta'] ) && is_array( $source['postmeta'] ) ) {
			foreach ( $source['postmeta'] as $key => $value ) {
				update_post_meta( $attachment_id, $key, $value );
			}
		}

		update_post_meta( $attachment_id, self::META_SOURCE_BLOG, $source_blog_id );
		update_post_meta( $attachment_id, self::META_SOURCE_POST, $source_attachment );

		/**
		 * Fires after a sideload operation.
		 *
		 * @param int   $attachment_id     The ID of the sideloaded attachment.
		 * @param array $file_array        The file array.
		 * @param array $post_data         The post data.
		 * @param int   $source_blog_id    The ID of the source blog.
		 * @param int   $source_attachment The ID of the source attachment.
		 */
		do_action( 'cross_site_media_after_sideload', $attachment_id, $file_array, $post_data, $source_blog_id, $source_attachment );

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
		$query = new WP_Query(
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

		if ( $query->have_posts() && is_int( $query->posts[0] ) ) {
			return (int) $query->posts[0];
		}

		return 0;
	}

	/**
	 * Read the source attachment under switch_to_blog and return everything we need
	 * to recreate it on the current site.
	 *
	 * @param int $source_blog_id    Blog to read from.
	 * @param int $source_attachment Attachment post ID on the source blog.
	 *
	 * @return array|WP_Error
	 */
	private function read_source( int $source_blog_id, int $source_attachment ): array|WP_Error {
		switch_to_blog( $source_blog_id );

		$post = get_post( $source_attachment );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			restore_current_blog();
			return new WP_Error(
				'cross_site_media_not_found',
				__( 'Source attachment not found.', 'cross-site-media' ),
				[ 'status' => 404 ]
			);
		}

		$file_path = get_attached_file( $source_attachment, true );
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			restore_current_blog();
			return new WP_Error(
				'cross_site_media_unreadable',
				__( 'Source media file is not readable.', 'cross-site-media' ),
				[ 'status' => 500 ]
			);
		}

		$data = [
			'filename'    => wp_basename( $file_path ),
			'path'        => $file_path,
			'title'       => (string) $post->post_title,
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'metadata'    => wp_get_attachment_metadata( $source_attachment ),
			'postmeta'    => get_post_meta( $source_attachment ),
		];

		/**
		 * Filters the source data for an attachment before it is sideloaded.
		 *
		 * @param array $data              The source data (file data, post data, and metadata).
		 * @param int   $source_blog_id    The ID of the source blog.
		 * @param int   $source_attachment The ID of the source attachment.
		 *
		 * @return array
		 */
		$data = apply_filters( 'cross_site_media_source_data', $data, $source_blog_id, $source_attachment );

		restore_current_blog();

		return $data;
	}
}

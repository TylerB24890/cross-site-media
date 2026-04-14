/**
 * Custom Attachments query that injects `cross_site_blog_id` into every request.
 *
 * `wp.media.model.Query.sync()` builds the AJAX payload from `this.props.toJSON()`,
 * so the simplest way to make every fetch go to a given subsite is to seed the
 * props with the blog id. The PHP MediaAjaxProxy (and RestMediaProxy) picks that
 * up and calls switch_to_blog before core's handler runs.
 */

/**
 * Build an Attachments collection scoped to a remote subsite.
 *
 * @param {number} blogId Source blog id.
 * @param {object} [extraProps] Additional default props (search, type, etc.).
 * @returns {object} wp.media.model.Attachments collection.
 */
export function buildRemoteAttachments(blogId, extraProps = {}) {
	const { media } = window.wp;

	// Add the cross_site_blog_id to the query props.
	return media.query({
		...extraProps,
		cross_site_blog_id: blogId,
	});
}

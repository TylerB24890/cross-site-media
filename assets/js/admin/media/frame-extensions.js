/**
 * Add one router tab per accessible subsite to wp.media's Select and Post frames.
 */

import { getConfig } from './config';
import { buildRemoteAttachments } from './query';

/**
 * Content-mode id convention.
 * Centralized so router tabs and content handlers stay in sync.
 *
 * @param {number} blogId Source blog id.
 * @returns {string} Content-mode key.
 */
function modeIdFor(blogId) {
	return `cross-site-media-browse-${blogId}`;
}

/**
 * Append router tab entries for each subsite.
 *
 * @param {object} routerView Router menu view.
 * @param {Array}  subsites   Subsite descriptors.
 */
function addSubsiteRouterTabs(routerView, subsites) {
	subsites.forEach((site, index) => {
		routerView.set(modeIdFor(site.blog_id), {
			text: site.name,
			// Place subsite tabs after "Media Library".
			priority: 45 + index,
		});
	});
}

/**
 * Register `content:create:{mode}` handlers that render
 * an AttachmentsBrowser for a subsite's remote collection.
 *
 * @param {object} frame    Frame instance (on initialize/bindHandlers).
 * @param {Array}  subsites Subsite descriptors.
 */
function bindSubsiteContentHandlers(frame, subsites) {
	const { media } = window.wp;

	subsites.forEach((site) => {
		frame.on(
			`content:create:${modeIdFor(site.blog_id)}`,
			function contentCreate(contentRegion) {
				const state = this.state();
				this.$el.removeClass('hide-toolbar');

				// Create the subsite AttachmentsBrowser with filtering enabled.
				// eslint-disable-next-line no-param-reassign
				contentRegion.view = new media.view.AttachmentsBrowser({
					controller: this,
					collection: buildRemoteAttachments(site.blog_id),
					selection: state.get('selection'),
					model: state,
					sortable: false,
					search: true,
					filters: 'all',
					date: true,
					display: false,
					dragInfo: false,
				});
			},
			frame,
		);
	});
}

/**
 * Wrap a frame prototype so each instance registers subsite tabs/handlers.
 *
 * @param {Function} Frame    Frame constructor.
 * @param {Array}    subsites Subsite descriptors.
 */
function wrapFrameClass(Frame, subsites) {
	if (Object.prototype.hasOwnProperty.call(Frame.prototype, 'bindHandlers')) {
		const orig = Frame.prototype.bindHandlers;
		Frame.prototype.bindHandlers = function bindHandlers(...args) {
			orig.apply(this, args);
			bindSubsiteContentHandlers(this, subsites);
		};
	}

	if (Object.prototype.hasOwnProperty.call(Frame.prototype, 'browseRouter')) {
		const orig = Frame.prototype.browseRouter;
		Frame.prototype.browseRouter = function browseRouter(...args) {
			const [routerView] = args;
			orig.apply(this, args);
			addSubsiteRouterTabs(routerView, subsites);
		};
	}
}

/**
 * Public entrypoint.
 * Wires Select and Post frames.
 */
export function extendMediaFrames() {
	const config = getConfig();
	if (!config) {
		return;
	}

	const { subsites } = config;
	const { media } = window.wp || {};

	if (!media || !media.view || !media.view.MediaFrame || !subsites.length) {
		return;
	}

	const { Select, Post } = media.view.MediaFrame;

	// upload.php select frame.
	if (Select) {
		wrapFrameClass(Select, subsites);
	}

	// post-new.php/post.php frame.
	if (Post && Post !== Select) {
		wrapFrameClass(Post, subsites);
	}
}

/**
 * Add one router tab per accessible subsite to wp.media's Select and Post frames.
 *
 * Architecture note — router tabs operate on CONTENT MODES, not on states.
 * Clicking a tab calls `frame.content.mode( contentMode )`; it does not
 * activate a state. Core's "Media Library" tab works because its content
 * mode `'browse'` is wired to `browseContent`, which renders an
 * AttachmentsBrowser using the currently-active state's library collection.
 *
 * We reuse the same plumbing per subsite by (a) registering a unique content
 * mode per subsite, (b) binding `content:create:{mode}` to a handler that
 * builds an AttachmentsBrowser against that subsite's remote Attachments
 * collection (which injects `cross_site_blog_id` into every fetch), and
 * (c) leaving the frame's state untouched so selection, insert behavior, and
 * the "Media Library" tab all keep working. Remote picks land in the same
 * state.selection collection and get intercepted by our sideload handler.
 */

import { getConfig } from './config';
import { buildRemoteAttachments } from './query';

/**
 * Content-mode id convention. Centralized so router tabs and content handlers
 * stay in sync.
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
			// Core tabs: upload=20, browse=40. Place subsite tabs after "Media Library".
			priority: 45 + index,
		});
	});
}

/**
 * Register `content:create:{mode}` handlers that render an AttachmentsBrowser
 * backed by a subsite's remote collection.
 *
 * The AttachmentsBrowser model is the active state — the same one core passes
 * into `browseContent`. This keeps the existing state's selection (and thus
 * the Insert/Select toolbar behavior) wired through unchanged; remote picks
 * land in the same selection collection and get intercepted by our sideload
 * handler on `selection.add`.
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

				// Search/Filter/Date views in the AttachmentsBrowser bind to
				// `collection.props`, not the state — so passing our remote
				// collection means its own props receive search/filter/date
				// updates and fetches re-run against the right subsite.
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
 * Only wraps methods owned by the prototype (hasOwnProperty). `Post` extends
 * `Select`; `Select` owns `browseRouter` and `Post` inherits it. Wrapping
 * only the owning class prevents double-registration when a Post frame
 * constructs (the inherited wrapped browseRouter runs exactly once).
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
 * Public entrypoint — wires Select and Post frames.
 */
export function extendMediaFrames() {
	const config = getConfig();
	if (!config) {
		return;
	}

	const { media } = window.wp || {};
	if (!media || !media.view || !media.view.MediaFrame) {
		return;
	}

	const { subsites } = config;
	if (!subsites.length) {
		return;
	}

	const { Select, Post } = media.view.MediaFrame;

	if (Select) {
		wrapFrameClass(Select, subsites);
	}

	if (Post && Post !== Select) {
		wrapFrameClass(Post, subsites);
	}
}

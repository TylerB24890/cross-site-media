/**
 * Auto-sideload handler.
 *
 * When a user selects an attachment from a subsite tab, we copy it into the
 * current site so that downstream consumers (featured image, gallery blocks,
 * anything that expects a local attachment ID) keep working unmodified.
 *
 * Why swap-in-place on `selection.add` instead of hooking the toolbar's select
 * button: Backbone events don't have priorities, so we can't guarantee running
 * before core's bound handler. Mutating the model's attributes before the user
 * clicks Insert means core sees a fully-local attachment by the time it runs.
 */

import apiFetch from '@wordpress/api-fetch';

import { getConfig } from './config';

/**
 * Models that are currently mid-sideload. Used to disable the toolbar button
 * until everything pending resolves.
 */
const pending = new Set();

/**
 * Single active frame's toolbar button, kept disabled while `pending` is non-empty.
 *
 * @type {?object}
 */
let activeFrame = null;

/**
 * Recompute the Select/Insert button's disabled state for the active frame.
 */
function refreshToolbar() {
	if (!activeFrame) {
		return;
	}
	const toolbar =
		activeFrame.toolbar && activeFrame.toolbar.get ? activeFrame.toolbar.get() : null;
	if (!toolbar || !toolbar.get) {
		return;
	}
	const button = toolbar.get('select') || toolbar.get('insert');
	if (!button) {
		return;
	}
	button.model.set('disabled', pending.size > 0);
}

/**
 * Swap a remote attachment model's attributes with the local copy returned by
 * the sideload endpoint. Backbone's `change` event propagates into the media
 * grid + details sidebar, so the UI updates on its own.
 *
 * @param {object} model Attachment model.
 * @param {object} localAttachment Response from the /sideload endpoint.
 */
function replaceWithLocalCopy(model, localAttachment) {
	// Preserve the cross-site origin in a detached attribute so downstream UI
	// (the details badge) can still reflect "originally from Site X".
	const origin = model.get('crossSiteMedia');

	// `set({}, { unset: true })` would drop attributes we don't want to drop.
	// Instead, clear the current attrs and layer on the new payload so the
	// model's `id` matches the local attachment id.
	const nextAttributes = {
		...localAttachment,
		crossSiteMediaOrigin: origin,
	};
	delete nextAttributes.crossSiteMedia;

	model.clear({ silent: true });
	model.set(nextAttributes);
}

/**
 * Perform the sideload request for a remote attachment model.
 *
 * @param {object} model Attachment model tagged with `crossSiteMedia`.
 */
function sideloadModel(model) {
	const config = getConfig();
	if (!config) {
		return;
	}

	const origin = model.get('crossSiteMedia');
	if (!origin || !origin.sourceBlogId || !origin.sourceAttachId) {
		return;
	}

	pending.add(model.cid);
	refreshToolbar();

	apiFetch({
		path: `/${config.rest.sideloadRoute}`,
		method: 'POST',
		data: {
			source_blog_id: origin.sourceBlogId,
			source_attachment_id: origin.sourceAttachId,
		},
	})
		.then((localAttachment) => {
			replaceWithLocalCopy(model, localAttachment);
		})
		.catch((error) => {
			// Surface the failure visibly but non-fatally. Remove the item from
			// the selection so the user can retry without inserting a broken ref.
			// eslint-disable-next-line no-console
			console.error('cross-site-media sideload failed', error);

			if (activeFrame && activeFrame.state && activeFrame.state()) {
				const selection = activeFrame.state().get('selection');
				if (selection) {
					selection.remove(model);
				}
			}

			window.alert(config.strings.sideloadFail); // eslint-disable-line no-alert
		})
		.finally(() => {
			pending.delete(model.cid);
			refreshToolbar();
		});
}

/**
 * Bind our selection listener to a frame instance each time it opens.
 *
 * @param {object} frame wp.media frame.
 */
function attachToFrame(frame) {
	activeFrame = frame;

	const handleAdd = (model) => {
		if (!model.get('crossSiteMedia')) {
			return;
		}
		sideloadModel(model);
	};

	// The active state's selection is what binds to the toolbar button. Listen
	// across state changes so tabs flip between subsites cleanly.
	const onStateChange = () => {
		const state = frame.state();
		if (!state) {
			return;
		}
		const selection = state.get('selection');
		if (!selection) {
			return;
		}
		selection.off('add', handleAdd);
		selection.on('add', handleAdd);
	};

	frame.on('activate', onStateChange);
	onStateChange();

	frame.on('close', () => {
		pending.clear();
		if (activeFrame === frame) {
			activeFrame = null;
		}
	});
}

/**
 * Public entrypoint — hook the base MediaFrame prototype so every frame
 * instance (Select, Post, and any custom subclass that extends them) runs
 * our selection listener after core finishes wiring itself up.
 */
export function installSideloadHandler() {
	const { media } = window.wp || {};
	if (!media || !media.view || !media.view.MediaFrame) {
		return;
	}

	const { Select, Post } = media.view.MediaFrame;

	const wrap = (Frame) => {
		const originalInitialize = Frame.prototype.initialize;
		Frame.prototype.initialize = function initialize(...args) {
			originalInitialize.apply(this, args);
			attachToFrame(this);
		};
	};

	if (Select) {
		wrap(Select);
	}
	if (Post && Post !== Select) {
		wrap(Post);
	}
}

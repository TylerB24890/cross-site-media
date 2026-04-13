/**
 * Adds a "From: {Subsite}" badge to the details sidebar whenever the selected
 * attachment is currently being served from another blog (pre-sideload) or was
 * originally sourced from one (post-sideload via the `crossSiteMediaOrigin`
 * attribute we stamp onto swapped models).
 */

import { getConfig } from './config';

/**
 * Extend `wp.media.view.Attachment.Details` to append an origin badge.
 */
export function installDetailsBadge() {
	const { media } = window.wp || {};
	const config = getConfig();
	if (!media || !media.view || !media.view.Attachment || !config) {
		return;
	}

	const { Details } = media.view.Attachment;
	if (!Details) {
		return;
	}

	const originalRender = Details.prototype.render;

	Details.prototype.render = function render(...args) {
		const result = originalRender.apply(this, args);

		const origin = this.model.get('crossSiteMedia') || this.model.get('crossSiteMediaOrigin');

		if (origin && origin.sourceBlogName) {
			const existing = this.$el.find('.cross-site-media-origin');
			if (existing.length) {
				existing.remove();
			}
			const label = config.strings.badge.replace('%s', origin.sourceBlogName);
			const badge = window.document.createElement('div');
			badge.className = 'cross-site-media-origin';
			badge.textContent = label;
			this.$el.prepend(badge);

			// Disable editable fields when the user can't edit on the origin.
			if (this.model.get('crossSiteMedia') && origin.canEditOnOrigin === false) {
				this.$el
					.find('input, textarea')
					.prop('disabled', true)
					.attr('readonly', 'readonly');
			}
		}

		return result;
	};
}

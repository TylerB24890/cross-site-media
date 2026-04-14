/**
 * Add a subsite dropdown to the Media Library screen at `upload.php`.
 */

import { getConfig } from './config';

/**
 * Tiny local HTML-escape for <option> text.
 * We're avoiding core's `_.escape` in case a plugin replaces Underscore at runtime.
 *
 * @param {string} input Text to escape.
 * @returns {string} Escaped text.
 */
function escapeHtml(input) {
	return String(input)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

/**
 * Build a Backbone view that renders our subsite <select> and keeps
 * the library collection's `cross_site_blog_id` prop in sync with it.
 *
 * @param {object} controller Media frame instance.
 * @param {object} config     Localized runtime config.
 * @returns {object} View instance.
 */
function buildSubsiteSelectView(controller, config) {
	const { media } = window.wp;
	const SubsiteSelectView = media.View.extend({
		tagName: 'div',
		className: 'media-filter-container cross-site-media-manage-filter',
		events: {
			'change select': 'onChange',
		},

		initialize() {
			this.listenTo(this.collection.props, `change:${config.rest.blogIdParam}`, this.select);
		},

		render() {
			const options = [
				`<option value="">${escapeHtml(config.strings.manageThisSite)}</option>`,
			];
			config.subsites.forEach((site) => {
				options.push(`<option value="${site.blog_id}">${escapeHtml(site.name)}</option>`);
			});

			this.$el.html(
				[
					'<label for="cross-site-media-filter">',
					escapeHtml(config.strings.manageLabel),
					'</label>',
					'<select id="cross-site-media-filter" class="cross-site-media-select">',
					options.join(''),
					'</select>',
				].join(''),
			);

			this.select();
			return this;
		},

		/**
		 * Mirror the collection's current `cross_site_blog_id` into the <select>.
		 */
		select() {
			const current = this.collection.props.get(config.rest.blogIdParam) || '';
			this.$('select').val(String(current));
			this.controller.$el.toggleClass('cross-site-media-viewing-remote', Boolean(current));
		},

		onChange(event) {
			const raw = event.target.value;
			if (!raw) {
				this.collection.props.unset(config.rest.blogIdParam);
			} else {
				this.collection.props.set(config.rest.blogIdParam, Number(raw));
			}
		},
	});

	return new SubsiteSelectView({
		controller,
		collection: controller.state().get('library'),
		priority: -70,
	});
}

/**
 * Public entrypoint.
 * Attaches a subsite dropdown to every AttachmentsBrowser.
 */
export function extendManageFrame() {
	const config = getConfig();
	if (!config) {
		return;
	}

	const { media } = window.wp || {};
	if (!media || !media.view || !media.view.MediaFrame || !media.view.MediaFrame.Manage) {
		return;
	}

	const { Manage } = media.view.MediaFrame;
	if (!Object.prototype.hasOwnProperty.call(Manage.prototype, 'browseContent')) {
		return;
	}

	const orig = Manage.prototype.browseContent;
	Manage.prototype.browseContent = function browseContent(...args) {
		orig.apply(this, args);

		// After core finishes building the AttachmentsBrowser + toolbar,
		// plug our <select> into the toolbar.
		const browser = this.browserView;
		if (!browser || !browser.toolbar) {
			return;
		}

		const view = buildSubsiteSelectView(this, config);
		view.render();
		browser.toolbar.set('cross-site-media-select', view);
	};
}

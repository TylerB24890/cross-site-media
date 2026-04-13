/**
 * Project-level webpack config.
 *
 * We extend 10up-toolkit's default config (required directly from the package)
 * rather than re-defining the build from scratch. The only modification is to
 * strip the bundled `webpackbar` plugin, which ships options that the pinned
 * webpack version rejects at runtime. Dropping the progress reporter doesn't
 * change the build output — it only affects terminal progress rendering.
 *
 * Everything else (entry points, loaders, WP externals, asset manifests) comes
 * unchanged from the toolkit.
 */

// eslint-disable-next-line import/no-extraneous-dependencies
const toolkitConfig = require('10up-toolkit/config/webpack.config');

/**
 * Remove WebpackBar instances from a plugin list.
 *
 * @param {Array} plugins Webpack plugins array.
 * @returns {Array} Filtered plugins.
 */
function stripWebpackBar(plugins) {
	return plugins.filter(
		(plugin) => plugin && plugin.constructor && plugin.constructor.name !== 'WebpackBarPlugin',
	);
}

/**
 * Apply the plugin filter to a single toolkit config object.
 *
 * @param {object} config Toolkit-produced webpack config.
 * @returns {object} Same config with WebpackBar removed.
 */
function patch(config) {
	if (config && Array.isArray(config.plugins)) {
		config.plugins = stripWebpackBar(config.plugins);
	}
	return config;
}

module.exports = Array.isArray(toolkitConfig) ? toolkitConfig.map(patch) : patch(toolkitConfig);

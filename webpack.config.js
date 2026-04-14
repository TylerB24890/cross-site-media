/**
 * Webpack overrides.
 */

// eslint-disable-next-line import/no-extraneous-dependencies
const toolkitConfig = require('10up-toolkit/config/webpack.config');

/**
 * Remove WebpackBar instances from a plugin list.
 *
 * @todo Remove this once 10up-toolkit updates the pinned webpack version.
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

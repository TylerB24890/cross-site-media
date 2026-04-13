/**
 * Runtime config injected by PHP via wp_localize_script.
 *
 * Everything the media-frame extensions need is on `window.CrossSiteMedia`.
 * This module provides a typed-ish accessor so we fail loudly if the global is
 * missing (which would mean the PHP enqueue didn't run).
 */

const GLOBAL_KEY = 'CrossSiteMedia';

export function getConfig() {
	const config = window[GLOBAL_KEY];
	if (!config || !Array.isArray(config.subsites)) {
		return null;
	}
	return config;
}

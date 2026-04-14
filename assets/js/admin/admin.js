import '../../css/admin/admin-style.css';

import { getConfig } from './media/config';
import { extendMediaFrames } from './media/frame-extensions';
import { extendManageFrame } from './media/manage-extension';
import { installSideloadHandler } from './media/sideload';
import { installDetailsBadge } from './media/details-badge';

/**
 * Ensure we can access the media frame and config data before booting the plugin.
 */
function boot() {
	if (!getConfig() || !window.wp || !window.wp.media) {
		return;
	}

	extendMediaFrames();
	extendManageFrame();
	installSideloadHandler();
	installDetailsBadge();
}

boot();

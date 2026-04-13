import '../../css/admin/admin-style.css';

import { getConfig } from './media/config';
import { extendMediaFrames } from './media/frame-extensions';
import { installSideloadHandler } from './media/sideload';
import { installDetailsBadge } from './media/details-badge';

/**
 * Boot: `wp.media` is available as soon as `media-views` loads, which is a
 * declared dependency of this bundle, so we don't need to defer further.
 */
function boot() {
	if (!getConfig() || !window.wp || !window.wp.media) {
		return;
	}

	extendMediaFrames();
	installSideloadHandler();
	installDetailsBadge();
}

boot();

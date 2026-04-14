# Cross Site Media

A WordPress plugin that lets users browse and select media from any subsite in a Multisite network, directly from the media library UI they already know.

Unlike "shared media library" plugins that funnel all uploads into a single site, Cross Site Media keeps each subsite's library independent. Users see additional tabs (or a dropdown) for every subsite they have access to, and selecting a remote asset automatically copies it into the current site so downstream features (featured images, gallery blocks, srcset) keep working without custom rendering hooks.

## Requirements

| Dependency | Version |
|---|---|
| WordPress | 6.7+ |
| PHP | 8.2+ |
| WordPress Multisite | Enabled (subdirectory or subdomain) |

The plugin shows a notice and does nothing on single-site installs.

## Features

- **Per-subsite tabs in the media modal** -- Insert Media, Featured Image, Gallery, and any other `wp.media` picker gain one tab per accessible subsite, right after the built-in "Media Library" tab.
- **Media Library Dropdown** -- A `<select>` filter in the Media Library to filter attachments to a specific subsite.
- **Transparent sideloading** -- Selecting a remote attachment copies the file and its metadata (title, caption, description, alt text, etc..) into the current site. The result is a real local `attachment` post with its own ID, so featured images, gallery blocks, and any consumer that expects a local post ID keep working.
- **Deduplication** -- Will not upload the same image if it has already been copied from a subsite.
- **Access control** -- Only subsites where the current user has the `upload_files` capabillity appear. Editing remote metadata in the details sidebar requires `edit_post` on the origin. Delete actions are suppressed on remote items.
- **Origin tracking** -- Sideloaded attachments carry `_cross_site_media_source_blog_id` and `_cross_site_media_source_attachment_id` postmeta. The details sidebar shows a "From {Site}" badge.
- **Search and filter** -- Type, date, and search filters on subsite tabs query the remote site, not the local library.

## Installation

```bash
# Clone into your plugins directory
cd wp-content/plugins/
git clone <repo-url> cross-site-media
cd cross-site-media

# Install PHP dependencies
composer install

# Install Node dependencies (requires Node 20 or 22)
npm install

# Build production assets
npm run build
```

Activate the plugin **network-wide** from Network Admin > Plugins or add to the `mu-plugins` directory.

## Development

```bash
# Watch mode with hot reload
npm run watch

# Lint
npm run lint-js
npm run lint-style

# Format
npm run format-js

# PHP code standards (10up-Default)
composer run lint
composer run lint-fix
```

The build toolchain is [10up-toolkit](https://github.com/10up/10up-toolkit). A project-level `webpack.config.js` extends the toolkit's default config (the only modification strips a `WebpackBar` plugin that is incompatible with current webpack versions).

## How it works

### Media modal (Select / Post frames)

The plugin extends `wp.media.view.MediaFrame.Select` and `.Post` prototypes to:

1. **Add router tabs** via `browseRouter` -- one per accessible subsite, positioned after "Media Library" (priority 45+).
2. **Bind content handlers** via `bindHandlers` -- each tab's content mode renders an `AttachmentsBrowser` backed by a remote `Attachments` collection whose props include `cross_site_blog_id`.
3. **Intercept selection** via `selection.on('add')` -- when a remote attachment (tagged with `crossSiteMedia` by the server) is selected, the sideload handler POSTs to `/wp-json/cross-site-media/v1/sideload`, then swaps the Backbone model's attributes in place with the returned local attachment. By the time the user clicks Insert/Select, the model is fully local.

### Server-side blog switching

Core's media AJAX actions (`query-attachments`, `get-attachment`, `save-attachment`, etc.) and the REST `/wp/v2/media` endpoint are hooked at priority 0. When `cross_site_blog_id` is present in the request, the plugin validates the user's access and calls `switch_to_blog()` before core's handler runs. Core queries the right database tables without modification.

For list mode on `upload.php`, the switch happens during `load-upload.php` so the entire `WP_Media_List_Table` renders against the selected subsite.

### Architecture

```
src/
  PluginCore.php                    Main bootstrap (10up Framework module loader)
  Environment.php                   Single-site guard + admin notice

  Admin/
    MediaAssets.php                 Enqueue + localize JS/CSS for wp.media contexts
    ListModeFilter.php              Server-side dropdown + switch for upload.php list mode

  Http/
    MediaAjaxProxy.php             switch_to_blog on core admin-ajax media actions
    RestMediaProxy.php             switch_to_blog on /wp/v2/media REST routes
    PrepareAttachmentFilter.php    Decorate attachment JSON with crossSiteMedia origin data
    SideloadController.php         POST /cross-site-media/v1/sideload endpoint

  Support/
    AccessControl.php              Capability checks + request param reading
    Sideloader.php                 File copy + metadata clone + deduplication

assets/js/admin/
  admin.js                         Entry point
  media/
    config.js                      Read localized window.CrossSiteMedia
    frame-extensions.js            Extend Select/Post frames with subsite tabs
    manage-extension.js            Inject dropdown into Manage frame toolbar
    query.js                       Build remote Attachments collections
    sideload.js                    Auto-sideload on selection + model swap
    details-badge.js               Origin badge + field disable in details pane

assets/css/admin/
  admin-style.css                  Dropdown, badge, and remote-viewing styles
```

## REST API

### POST `/wp-json/cross-site-media/v1/sideload`

Copy a remote attachment into the current site.

**Parameters:**

| Name | Type | Description |
|---|---|---|
| `source_blog_id` | integer | Blog to copy from (required) |
| `source_attachment_id` | integer | Attachment post ID on the source blog (required) |

**Response:** The local attachment in the same shape as `wp_prepare_attachment_for_js()`.

**Auth:** Requires `upload_files` on the current site and browse access on the source site.

## Filters and hooks

The plugin fires these WordPress actions during its lifecycle:

| Hook | When |
|---|---|
| `cross_site_media_loaded` | Plugin file loaded |
| `cross_site_media_before_init` | Before module initialization |
| `cross_site_media_init` | After all modules registered |
| `cross_site_media_source_data` | Filters source attachment data before being sideloaded into the target blog. |
| `cross_site_media_user_can_browse` | Determines if a user can browse attachments from a specific blog. |
| `cross_site_media_user_can_edit` | Determines if a user can edit attachments from a specific blog. |


## Known limitations

- **Upload.php list mode chrome** -- When viewing a remote subsite's library in list mode, the admin bar and page title reflect the source site (a side effect of `switch_to_blog`). Attachment edit links correctly route to the source site's admin.
- **No upload to remote** -- You cannot upload new files into a remote subsite from the current site's admin. The "Add Media File" button is visually dimmed in grid mode when a remote subsite is selected.
- **Independent copies** -- Sideloaded files are independent copies. Editing metadata on the origin after sideloading does not propagate to existing copies.
- **Storage duplication** -- The same remote image selected from multiple sites produces one copy per site.

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

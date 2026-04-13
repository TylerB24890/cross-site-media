# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin overview

`cross-site-media` is a WordPress plugin intended to share a media library between sites in a multisite network. It is scaffolded from 10up's plugin template and is currently in early stage — `src/` contains only `PluginCore.php`; all feature modules still need to be written.

- Text domain / slug: `cross-site-media`
- PHP requirement: `>=8.2`
- WordPress requirement: `>=5.0`
- PSR-4 root: `CrossSiteMedia\` → `src/`

## Commands

All JS/CSS tooling is driven by [`10up-toolkit`](https://www.npmjs.com/package/10up-toolkit) (webpack + ESLint + Stylelint + Jest under the hood).

```bash
composer install             # install PHP deps (required — plugin.php throws without vendor/autoload.php)
npm install
npm run watch                # dev server on port 5010 with Fast Refresh
npm run build                # production build into ./dist
npm run lint-js              # ESLint
npm run lint-style           # Stylelint
npm run format-js            # auto-format JS
npm run test                 # Jest (passes with no tests)
npm run clean-dist           # rm -rf ./dist
```

Run a single Jest test file:

```bash
npx 10up-toolkit test-unit-jest path/to/file.test.js
```

PHP linting is invoked by `lint-staged` via `../../vendor/bin/phpcs` (expects `phpcs` at `wp-content/vendor/bin/phpcs`; there is no plugin-local `composer require --dev` for it). There is no PHP test suite configured in this plugin.

JS build entry points are declared in `package.json` under the `10up-toolkit.entry` key (currently just `admin` → `assets/js/admin/admin.js`). Add new bundles there rather than inventing new webpack config.

## Architecture

### Bootstrap

`plugin.php` is the WordPress entry point. It:

1. Defines `CROSS_SITE_MEDIA_{VERSION,URL,PATH,INC,DIST_URL,DIST_PATH}` constants — use these rather than hard-coding paths.
2. Loads `dist/fast-refresh.php` when the environment is local (`wp_get_environment_type()` is `local`/`development`, or `home_url()` contains `.test`/`.local`) and `dist/fast-refresh.php` exists. This enables hot reload in dev.
3. Requires Composer's autoloader (throws if missing).
4. Instantiates `CrossSiteMedia\PluginCore`, registers activation/deactivation hooks, and calls `$plugin_core->setup()`.

### Auto-registered modules (the important pattern)

`PluginCore::setup()` hooks `init` at priority `apply_filters( 'cross_site_media_init_priority', 8 )`. At that point `ModuleInitialization::instance()->init_classes( CROSS_SITE_MEDIA_INC )` scans `src/` (via `spatie/structure-discoverer`) and auto-instantiates every class that:

- Is instantiable (has a no-arg constructor), AND
- Implements `TenupFramework\ModuleInterface`.

For each qualifying class it then calls `can_register()`; if true, it calls `register()`. This is the only wiring — there is no manual class list anywhere. **To add a new feature, drop a class into `src/` that uses the `TenupFramework\Module` trait and implements `ModuleInterface`; it will load automatically.**

Typical skeleton for a new module:

```php
namespace CrossSiteMedia;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

class MyFeature implements ModuleInterface {
    use Module;

    public function can_register() {
        return is_admin(); // or any context check
    }

    public function register() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
    }
}
```

Override `load_order()` (default `10`) on the trait to control relative init order between modules. This is independent from WordPress's `init` priority.

Retrieve a registered module instance with `PluginCore::get_module( MyFeature::class )`.

In production/staging the discovered class list is cached to `src/class-loader-cache/` by `FileDiscoverCacheDriver`. Set `TENUP_FRAMEWORK_DISABLE_CLASS_CACHE` to `true` to bypass. On VIP Go (`VIP_GO_APP_ENVIRONMENT` defined) caching is disabled automatically.

### Hooks exposed by the bootstrap

- `cross_site_media_loaded` — after `setup()` registers its hooks.
- `cross_site_media_before_init` — at the start of module init.
- `cross_site_media_init` — after all modules have registered.
- `cross_site_media_init_priority` — filter to change the `init` priority (default 8).
- `tenup_framework_module_init__{slug}` — fired per-module before `register()`, where slug is `sanitize_title( str_replace('\\', '-', $class_name) )`.

### Activation

`register_activation_hook` calls `PluginCore::activate()`, which runs `init()` synchronously (so any rewrite-registering module fires) and then `flush_rewrite_rules()`. Uninstall logic belongs in `uninstall.php` (not yet present).

## Lint / code style

- `.eslintrc.json` extends `@10up/eslint-config/wordpress`, adds `@typescript-eslint` parser+plugin, and disables `@wordpress/no-unsafe-wp-apis`.
- `lint-staged` runs Stylelint on `*.css`, 10up-toolkit lint-js on `*.{js,ts,jsx,tsx}`, and `phpcs` on `*.php` (expects it at `../../vendor/bin/phpcs`).

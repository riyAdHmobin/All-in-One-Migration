# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

All plugin code lives in `all-in-one-wp-migration/`. Paths below are relative to the repo root unless they're shown under a directory heading (for example, `controller/` means `all-in-one-wp-migration/lib/controller/`), and bare PHP file names such as `loader.php` and `functions.php` are in `all-in-one-wp-migration/`.

## What this is

**Project goal:** this repo is the reference for **SnapBak**, a clean-room WordPress backup/migration plugin by **Riyadh Mobin**. `docs/01-BRD.md` to `docs/06-Development-Plan.md` specify SnapBak (identifiers in `docs/02-SRS.md` §1.6), and `preview/index.html` is a static UI mock-up. The plugin code in `all-in-one-wp-migration/` is the original being studied, not SnapBak. Don't copy its code into SnapBak, and keep SnapBak's code out of this folder.

A copy of the **All-in-One WP Migration** WordPress plugin (ServMask, v6.77). It exports a WordPress site (database, media, plugins, themes) into a single `.wpress` archive and imports it elsewhere, with find/replace across the database during migration. It targets very old environments: PHP 5.2.17+ and WordPress 3.3+. Don't use newer PHP syntax such as closures, namespaces, short array syntax `[]`, or `??`.

## Build / test / lint

There's no build system, package manager, or test suite here: no composer, npm, or phpunit config. The plugin runs only inside a WordPress install, so to try it, copy or symlink `all-in-one-wp-migration/` into `wp-content/plugins/` and activate it.

- Syntax check a file: `php -l path/to/file.php`
- Syntax check everything: `find all-in-one-wp-migration -name '*.php' -not -path 'all-in-one-wp-migration/lib/vendor/*' -print0 | xargs -0 -n1 php -l`
- WP-CLI (from a WP install with the plugin active): `wp ai1wm backup` / `wp ai1wm restore <file>` (`all-in-one-wp-migration/lib/vendor/servmask/command/class-ai1wm-wp-cli-command.php`; doesn't work on Multisite)

The front-end JS in `all-in-one-wp-migration/lib/view/assets/javascript/*.min.js` is a set of **compiled webpack bundles with no source in this repo**. To change it, you have to edit the bundles directly.

## Architecture

**Bootstrap:** `all-in-one-wp-migration/all-in-one-wp-migration.php` defines path/URL constants, then requires `constants.php`, `deprecated.php`, `functions.php` (global `ai1wm_*` helpers, including all path helpers), `exceptions.php`, and `loader.php`. There's no autoloader: every class is `require_once`'d explicitly in `loader.php`, so **you must register new class files there**. Then `new Ai1wm_Main_Controller()` wires every hook.

**MVC layout under `all-in-one-wp-migration/lib/`:**
- `controller/`: static-method controllers bound to admin pages and `wp_ajax_ai1wm_*` actions (registered in `Ai1wm_Main_Controller`).
- `model/`: domain logic. `model/export/` and `model/import/` hold the pipeline steps.
- `view/`: PHP templates rendered through `Ai1wm_Template` (extends the bundled `Bandar` micro-templating lib). `Ai1wm_Template::render( 'export/index' )` maps to `all-in-one-wp-migration/lib/view/export/index.php`.
- `vendor/servmask/`: in-house libraries for the archiver (`.wpress` compressor/extractor), the database dumper with find/replace (MySQL and MySQLi variants), filesystem helpers, recursive iterators and filters, cron, and the WP-CLI command.

### The export/import pipeline (the core concept)

Export and import are each a chain of steps registered as WordPress filters on `ai1wm_export` / `ai1wm_import`, with **priority as step order** (see `Ai1wm_Main_Controller` around the `add_filter( 'ai1wm_export', ...)` block):

- Export: Init(5) → Compatibility(5) → Archive(10) → Config(50) → ConfigFile(60) → Enumerate(100) → Content(150) → Database(200) → DatabaseFile(220) → Download(250) → Clean(300)
- Import: Upload(5) → Compatibility(10) → Validate(50) → Confirm(100) → Blogs(150) → Enumerate(200) → Content(250) → MuPlugins(270) → Database(300) → Done(350) → Clean(400)

Each step is a class with a static `execute( $params )` that returns the updated `$params`. `Ai1wm_Export_Controller::export()` / `Ai1wm_Import_Controller::import()` walk `$wp_filter` manually, run only the hooks at the current `priority`, and then:
- If a step sets `$params['completed'] = false` (it ran past the ~10s `ai1wm_completed_timeout`), the **same** priority is re-requested.
- Otherwise `priority` advances to the next registered key.
- The next request is a non-blocking `wp_remote_post` back to `admin-ajax.php?action=ai1wm_export|import` (the request chains itself). With `ai1wm_manual_export` (or `ai1wm_manual_import` / `ai1wm_manual_restore`), it echoes JSON `$params` to the browser instead, and the JS drives the loop.

Consequences when writing or editing steps:
- **All resumable state lives in `$params`** (byte offsets such as `archive_bytes_offset`, `file_bytes_offset`, `filemap_bytes_offset`, `table_index`, `table_offset`, `query_offset`, plus processed/total counters). Steps must be re-entrant and resume from these offsets.
- Requests authenticate with `secret_key` (`ai1wm_verify_secret_key`, compared against the `AI1WM_SECRET_KEY` option), not the WP session. `wp_ajax_nopriv_*` is registered on purpose.
- Working files live in `all-in-one-wp-migration/storage/<params['storage']>/` (`ai1wm_storage_path()`): `filemap.list`, `database.sql`, `package.json`, `multisite.json`, `blogs.json`, and so on (names in `constants.php`). Finished backups go to `wp-content/ai1wm-backups/`.
- Progress goes to the UI through `Ai1wm_Status::*`, which writes the `AI1WM_STATUS` option, and the JS polls `ai1wm_status`. Any exception in a step is caught by the controller, reported through Status/Notification, and the storage dir is deleted.

Premium extensions (Dropbox, S3, Multisite, Unlimited, and others) plug in by adding their own filters at other priorities on `ai1wm_export`/`ai1wm_import`, and by overriding the `ai1wm_export_*`/`ai1wm_import_*` button filters and `ai1wm_max_chunk_size`. Keep those hook names and priority gaps stable.

### `.wpress` archive format

A flat sequence of entries. Each entry has a 4377-byte header packed as `a255` filename + `a14` size + `a12` mtime + `a4096` path, followed by the raw file bytes. The archive ends with an all-null 4377-byte EOF block. See `all-in-one-wp-migration/lib/vendor/servmask/archiver/class-ai1wm-archiver.php`.

## Conventions

- WordPress coding style: tabs, spaces inside parentheses, Yoda-ish `( $x = ... )` assignments in conditions, class names `Ai1wm_*`, files `class-ai1wm-*.php`.
- User-facing strings use `__( '...', AI1WM_PLUGIN_NAME )`.
- Build file paths with `DIRECTORY_SEPARATOR` and the `ai1wm_*_path()` helpers in `functions.php` rather than hardcoding paths.

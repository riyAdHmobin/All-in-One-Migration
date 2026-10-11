# 05 — Improvement Notes

What is wrong with the original plugin (All-in-One WP Migration v6.77, "AI1WM"), and what SnapBak must do differently. All source references are relative to the plugin root `all-in-one-wp-migration/`.

## Contents

1. [How to read this document](#1-how-to-read-this-document)
2. [Security problems](#2-security-problems)
3. [Correctness bugs](#3-correctness-bugs)
4. [Performance bottlenecks](#4-performance-bottlenecks)
5. [Code quality and outdated practices](#5-code-quality-and-outdated-practices)
6. [UX and accessibility gaps](#6-ux-and-accessibility-gaps)
7. [What to do differently (prioritised)](#7-what-to-do-differently-prioritised)
8. [Open questions](#8-open-questions)

---

## 1. How to read this document

- **Severity scale (security):** **High** means site takeover or full data disclosure is plausible under realistic conditions. **Medium** means it needs a precondition (e.g. knowing the secret key or tricking an admin into importing a crafted archive), or the impact is limited. **Low** means hardening or defence in depth.
- **Impact (bugs):** **Data** means it can corrupt or lose a backup or the site. **Func** means a feature misbehaves. **Cosmetic** means a wrong message or dead code.
- Security items describe the weakness, the impact and the fix. They deliberately do not include step-by-step attack instructions.
- Cross-references: functional requirements are in `02-SRS.md`, the architecture and endpoint details in `03-Technical-Architecture.md`, and the screens in `04-UI-UX-Spec.md`.

---

## 2. Security problems

### 2.1 The root cause

Every action endpoint (`ai1wm_export`, `ai1wm_import`, `ai1wm_status`, `ai1wm_backups`, `ai1wm_feedback`, `ai1wm_report`) is registered for both `wp_ajax_` and `wp_ajax_nopriv_` (`lib/controller/class-ai1wm-main-controller.php:902-915`). The only check on these endpoints is a single, never-rotated, 12-character secret key: `ai1wm_verify_secret_key`, which uses a non-constant-time `!==` comparison (`functions.php:1377-1383`). There are no nonces and no capability checks.

The design is deliberate: the self-chaining loopback requests carry no cookies. The consequence is that **the secret key is equivalent to full administrator power**, because an import replaces the database and writes arbitrary files. Most of the findings below are either ways that key leaks or places where holding it gives more power than necessary.

### 2.2 Findings

| ID | Sev | Weakness | Impact | Source | Fix in SnapBak |
|---|---|---|---|---|---|
| SEC-01 | High | Unauthenticated (`nopriv`) action endpoints guarded only by a static shared secret; no nonce, no `current_user_can`. | Whoever holds the key can export, import (full DB and file replacement), delete backups and read status without logging in. | `lib/controller/class-ai1wm-main-controller.php:902-915`; `functions.php:1377-1383` | Browser-initiated actions go through REST routes that require `current_user_can()` plus a REST nonce. Background continuation uses a per-job, short-lived HMAC token bound to the job ID and expiry, compared with `hash_equals`. No `nopriv` handlers. |
| SEC-02 | High | Finished backups live in a public directory (`wp-content/ai1wm-backups/`) under predictable names. The `.htaccess`/`web.config` only force a MIME type and disable listing; they do not deny access. Nginx gets no protection. | Full site archives, including password hashes and secrets stored in options, can be downloaded by anyone who can guess the name: domain, timestamp and a `rand(100,999)` suffix. | `functions.php:331-356`; `lib/vendor/servmask/filesystem/class-ai1wm-file-htaccess.php:34-46`; `class-ai1wm-file-webconfig.php:34-50` | Store backups outside the web root where possible. Otherwise use a directory with deny-all rules for Apache/IIS, plus a startup check that warns on Nginx when the files are reachable. Names get a ≥128-bit random suffix from a CSPRNG. Downloads only go through an authenticated, streaming endpoint. |
| SEC-03 | High | The secret key leaks through several channels: inlined in admin JS; sent as a GET query parameter to `ai1wm_status` every 3 s (so it ends up in access, proxy and Referer logs); written in plaintext to `export.log`/`import.log` on every step; sent in loopback POST bodies with TLS verification off; and readable by third-party scripts on the page (SEC-09). | Turns SEC-01 from theoretical into practical. | `lib/controller/class-ai1wm-main-controller.php:571-597, 647-683, 757-787`; `lib/model/class-ai1wm-log.php:28-64`; `lib/controller/class-ai1wm-export-controller.php:81` | No long-lived secret reaches the browser. Never put credentials in URLs. Redact secrets and tokens from every log. |
| SEC-04 | Medium | The working directory `storage/` lives inside the plugin directory, is web-reachable and is protected only by an `index.php`. Per-job folders hold `database.sql`, `package.json`, the in-progress archive and logs. Folder names come from `uniqid()` (time-based) or JS `Math.random`. The global `storage/error.log` has a fixed URL, grows without bound and contains absolute paths. | Information disclosure of the DB dump and secrets while a job runs, or permanently if Clean never ran (fatal error, CLI error). | `functions.php:32-44, 184-186, 507-509`; `lib/model/class-ai1wm-handler.php:37-55` | Put the working dir under `wp-content/uploads/<plugin>-private/` (or outside the web root) with deny rules. Use random job IDs (`random_bytes`). Rotate and cap logs. Run a scheduled cleanup of orphaned jobs. |
| SEC-05 | Medium | The server never checks the uploaded file's extension or type; the `.wpress` check is client-side only. The client-controlled `archive` name passes only `validate_file()`, which allows any extension. | With the key, a file of any type can be placed in the web-reachable storage directory. | `lib/model/import/class-ai1wm-import-upload.php:54-62`; `functions.php:52-87`; `lib/controller/class-ai1wm-main-controller.php:669-672` | The server generates archive names. Uploads are accepted only into a non-executable private directory with a fixed extension, and validated by magic header or manifest before use. |
| SEC-06 | Medium | No path containment when extracting archives: entry paths are joined onto the target dir without normalising `..` or absolute paths. | A crafted archive can write outside `wp-content` (e.g. into the document root). | `lib/vendor/servmask/archiver/class-ai1wm-extractor.php:161-260, 276-380` | Normalise every entry path, reject absolute paths and `..` segments, and verify the resolved path stays under the allowed root before writing. Treat the archive as untrusted input. |
| SEC-07 | Medium | `unserialize()` runs on database values from the imported dump with no `allowed_classes` restriction, then the code assigns properties on the resulting objects. | PHP object injection: gadget chains in any installed plugin may run during import. | `lib/vendor/servmask/database/class-ai1wm-database-utility.php:56-95` | Use a serialization-aware string replacer that never instantiates objects, or `unserialize($v, ['allowed_classes' => false])` with incomplete-class handling. |
| SEC-08 | Medium | HTTP Basic-auth credentials are captured from `$_SERVER` on **every** `admin_init`, including anonymous admin-ajax hits, and stored in plaintext autoloaded options. | Any request can overwrite them (breaking loopbacks on protected staging sites), and a real staging password ends up in plaintext in `wp_options`. | `lib/controller/class-ai1wm-main-controller.php:875-885` | Make Basic-auth credentials an explicit, admin-only setting saved through a capability- and nonce-checked form. Store them encrypted with a key derived from WP salts. Never capture them implicitly. |
| SEC-09 | Medium | The Facebook SDK and Twitter widgets JS are loaded into privileged admin pages (Export, Import, Backups); the Twitter script is loaded over HTTP on non-TLS admin pages. | Third-party code runs in the same JS context as the secret key. It is also a privacy and tracking issue and breaks a strict CSP. | `lib/view/common/share-buttons.php:28-49` | No third-party scripts in admin. Share or review links, if any, are plain links. |
| SEC-10 | Low-Med | Loopback requests default to `sslverify => false`. | If `admin_url()` resolves through an external proxy or CDN, credentials travel without certificate validation. | `lib/controller/class-ai1wm-export-controller.php:104-109`; `lib/controller/class-ai1wm-import-controller.php:108-113` | Verify TLS by default (filterable for self-signed dev setups). Better still, avoid HTTP loopbacks by using Action Scheduler or WP-Cron with a job queue. |
| SEC-11 | Low-Med | `X-Forwarded-Proto: https` from any client sets `$_SERVER['HTTPS']='on'` on **every** WordPress request, front end included, without any trusted-proxy check. | Request-controlled changes to `is_ssl()`, which affects URL generation, redirects, secure-cookie decisions and page caches site-wide. | `all-in-one-wp-migration.php:37-39` | Remove it. Reverse-proxy HTTPS detection belongs in `wp-config.php`, not in a migration plugin. |
| SEC-12 | Low | No CSRF protection on browser-initiated actions (all of them), including the updater's purchase-ID save. | A malicious page visited by an admin can trigger state changes. | `lib/controller/class-ai1wm-updater-controller.php:55-81` | Nonces on every state-changing request (covered by the REST design in SEC-01). |
| SEC-13 | Low | Status and error messages (which contain exception text with file paths and archive names) are rendered with jQuery `.html()`. The server-side messages also embed HTML deliberately. | DOM-XSS surface if an attacker-influenced name reaches a message. | `lib/view/assets/javascript/export.min.js` (status render, ~l.822-961), with the same pattern in `import.min.js` and `backups.min.js` | Use structured status (code, params, plain text) and render with `textContent` or escaped templates. Keep links in the UI layer, not in messages. |
| SEC-14 | Low | Output escaping gaps: `_e()` inside HTML attributes; backup href built without `esc_url`; filter output echoed raw; notices print paths unescaped. | Mostly translation-file or extension-supplied markup; low risk. | `lib/view/backups/index.php:74`; `lib/view/export/export-buttons.php:41`; `lib/view/import/import-buttons.php:50`; `lib/view/export/find-replace.php:40-41` | Escape at output (`esc_attr__`, `esc_url`, `wp_kses_post` for extension HTML). |
| SEC-15 | Low | No `defined('ABSPATH') \|\| exit;` guard in any PHP file. | Direct requests to class or view files can disclose paths through fatal errors when `display_errors` is on. | All 127 PHP files | Add the guard to every file; the views can't be executed directly. |
| SEC-16 | Low | Directories are created with mode `0777`. | World-writable storage and backup dirs on shared hosts. | `lib/vendor/servmask/filesystem/class-ai1wm-directory.php:34-36` | Use `wp_mkdir_p()`, which honours `FS_CHMOD_DIR`. |
| SEC-17 | Low | The backup-delete endpoint isn't restricted to archive files; it can delete `.htaccess`, `web.config` or `index.php` inside the backups dir. Failures are hidden. | Temporarily removes the directory's (already weak) protection. | `lib/model/class-ai1wm-backups.php:85-89` | Only operate on IDs from the server-side backup index. Report failures. |
| SEC-18 | Info | By design, an import executes the archive's SQL verbatim (after textual rewriting) with the site's DB credentials, and SQL errors are suppressed. | An imported archive is fully trusted code. Users must only import archives they created. | `lib/vendor/servmask/database/class-ai1wm-database.php:760-850` | Keep that trust model, but say it plainly in the UI. Restrict executed statements to the target tables (CREATE/DROP/INSERT on prefixed tables only), stop on error, and log failures. |
| SEC-19 | Info | Feedback and Report forms send the user's email and message to `servmask.com`. The site URL is sent on extension downloads. | Privacy and disclosure obligation. | `lib/model/class-ai1wm-feedback.php:51`; `lib/model/class-ai1wm-report.php:48`; `lib/model/class-ai1wm-updater.php:81` | Any telemetry or support form in SnapBak is opt-in, documented in the readme's privacy section, and goes through the WordPress privacy exporter if it stores data. |

---

## 3. Correctness bugs

| ID | Impact | Bug | Source | Note for SnapBak |
|---|---|---|---|---|
| BUG-01 | Func | `AI1WM_MAX_FILE_SIZE` is defined as `2 << 28 * 4`. Precedence makes this `2 << 112`, which is **0 on PHP 7+**, and 0 means "unlimited". The free import cap therefore never applies on modern PHP. **Unclear/likely bug:** the intended value was probably 512 MB (changelog 1.2.0). | `constants.php:284` | Use an explicit, filterable limit, or no limit. Don't rely on an expression whose value depends on the platform. |
| BUG-02 | Data | The content loop uses the truthiness of `trim(fgets())`, so a filemap line that is empty or is exactly `0` ends archiving early with `completed=true`. Stale byte offsets then flow into Database_File, which writes `database.sql` at an old offset and truncates the content. | `lib/model/export/class-ai1wm-export-content.php` (main loop) | Loop on `fgets() !== false`. Clear per-step offsets when a step completes. |
| BUG-03 | Data | If the Download step is retried after the archive has already been moved, it creates an empty archive and renames it over the good backup. | `lib/model/export/class-ai1wm-export-download.php:34-40` | Make steps idempotent: if the target exists and the source doesn't, the step is already done. |
| BUG-04 | Data | Chunked upload **appends** each chunk with no offset or sequence number. A lost response followed by a retry duplicates a chunk, and only the trailing EOF check can detect truncation, not duplication. | `lib/model/import/class-ai1wm-import-upload.php:54-62` | Send chunk offsets; the server writes at the offset and verifies the total size and a checksum at the end. |
| BUG-05 | Data | The SQL literal regex mis-tokenises strings that end in an escaped backslash, so replacements run across literal boundaries. | `lib/vendor/servmask/database/class-ai1wm-database.php` (replace pass) | Use a real tokenizer for SQL literals, or a dump format that stores rows as structured data (e.g. JSON lines). |
| BUG-06 | Data | Table-prefix and collation replacement run case-insensitively over the **whole statement**, data included, and **before** the serialization-aware pass. Serialized values containing the prefix token end up with wrong lengths. | `lib/vendor/servmask/database/class-ai1wm-database.php` (import) | Rewrite identifiers only. Do data replacement in a single serialization-aware pass. |
| BUG-07 | Data | Statements larger than `max_allowed_packet` are skipped silently. All SQL errors except 1030 are ignored. | `lib/vendor/servmask/database/class-ai1wm-database.php:760-850` | Split multi-row inserts to fit the packet. Fail loudly and report which table or row failed. |
| BUG-08 | Data | Tables without a primary key are paged with `ORDER BY 1` + OFFSET, which is nondeterministic, so rows can be duplicated or lost. | `lib/vendor/servmask/database/class-ai1wm-database.php:662` | Order by all columns, or by a unique index when one exists. Otherwise dump in one pass using an unbuffered query. |
| BUG-09 | Func | Views are exported as tables with an empty CREATE statement. | `lib/vendor/servmask/database/class-ai1wm-database.php` (export) | Detect `TABLE_TYPE='VIEW'` and export the `CREATE VIEW`, or skip views with a warning. |
| BUG-10 | Cosmetic | A timeout during the first table re-writes the dump header comments partway through the file. | `lib/vendor/servmask/database/class-ai1wm-database.php:563+` | Write the header in a separate init step. |
| BUG-11 | Func | Raw replacements (e.g. the uploads path) apply to any matching literal anywhere in the dump. | `lib/model/import/class-ai1wm-import-database.php:76-110` | Scope path replacements to known columns and options. |
| BUG-12 | Func | URL variants (www/no-www, http/https) are replaced only when the primary URL differs. The www inversion uses a naive `str_ireplace('//', '//www.')` on the whole URL. | `lib/model/import/class-ai1wm-import-database.php` | Build URL variants from parsed components. Always include every scheme and host variant. |
| BUG-13 | Func | Serialized objects of classes not loaded at import time (`__PHP_Incomplete_Class`) are not URL-replaced. | `lib/vendor/servmask/database/class-ai1wm-database-utility.php:56-95` | The string-level serialized replacer from SEC-07 fixes this too. |
| BUG-14 | Func | The Mu_Plugins and Enumerate steps are not time-sliced. Mu_Plugins ignores the extractor's "not completed" result, so it can leave a partial extraction. | `lib/model/import/class-ai1wm-import-mu-plugins.php`; `lib/model/import/class-ai1wm-import-enumerate.php`; `lib/model/export/class-ai1wm-export-enumerate.php:82-118` | Every step must be resumable. |
| BUG-15 | Func | `max_chunk_size` returns 0 when a php.ini value is `"0"` (meaning unlimited). `ai1wm_parse_size` uses decimal multipliers (k=1000). | `lib/controller/class-ai1wm-import-controller.php:154-160`; `functions.php:299-313` | Treat 0 as unlimited, use `wp_convert_hr_to_bytes()`, and set a floor. |
| BUG-16 | Func | The client-side uploader halves the chunk size on HTTP 413 without a floor and never restores it. It ignores the server-provided `max_retries` (10) and hard-codes 30. | `lib/view/assets/javascript/import.min.js` (uploader) | Set a minimum chunk size and fail cleanly below it. Honour the server's retry setting. |
| BUG-17 | Func | The import step loop in JS retries forever; the export loop stops after 5 tries. | `lib/view/assets/javascript/import.min.js`, `export.min.js` | Use a shared, bounded retry policy with backoff and a user-visible error. |
| BUG-18 | Func | The controllers' catch blocks call `ai1wm_storage_path()`, which can itself throw. PHP 7 `Error`s (not `Exception`s) are not caught at all, so fatals leave the job hanging. | `lib/controller/class-ai1wm-export-controller.php:84-90`; import controller (same lines) | Catch `Throwable`, mark the job failed and clean up with a function that cannot throw. |
| BUG-19 | Func | Division by zero is possible in progress math (0 tables, empty archive, empty `database.sql`); this is fatal on PHP 8. | `lib/model/export/class-ai1wm-export-database.php`; `lib/model/import/class-ai1wm-import-validate.php` | Guard every ratio. |
| BUG-20 | Data | Archive headers silently truncate names over 255 bytes and paths over 4096 bytes. Unreadable or vanished files are skipped without a warning. | `lib/vendor/servmask/archiver/class-ai1wm-archiver.php:53-58`; `class-ai1wm-compressor.php` | Use length-prefixed fields and record skipped files in the job report. |
| BUG-21 | Func | `trim()` on archive header fields strips legitimate leading or trailing spaces in file names. | `lib/vendor/servmask/archiver/class-ai1wm-extractor.php` | Strip only the NUL padding. |
| BUG-22 | Func | Export options are tested with `isset`, so posting `options[no_media]=0` still excludes media. | `lib/model/export/*` | Parse booleans explicitly. |
| BUG-23 | Func | `no_post_revisions` leaves revision postmeta behind. On multisite, the spam and options exclusions apply only to main-site tables. | `lib/model/export/class-ai1wm-export-database.php:71-130` | Exclude dependent meta with a join. Apply exclusions per blog table. |
| BUG-24 | Func | The table include rule is "starts with base_prefix", so it also catches unrelated tables in a shared database. | `lib/model/export/class-ai1wm-export-database.php` | Match the prefix plus known WP tables, or `$wpdb->tables()` plus a user-confirmed extra list. |
| BUG-25 | Func | WP-CLI `--replace` with more than one pair builds overlapping pairs (steps by 1 instead of 2). Storage isn't cleaned after a CLI error. CLI errors contain HTML tags. There is a "WPI CLI" typo. | `lib/vendor/servmask/command/class-ai1wm-wp-cli-command.php` | Parse pairs strictly, use `try/finally` cleanup and print plain-text errors. |
| BUG-26 | Func | Done activates the template and stylesheet without checking the theme exists. It doesn't flush rewrite rules; instead it tells the user to save permalinks twice. The deactivation block is copy-pasted three times. | `lib/model/import/class-ai1wm-import-done.php:50-64, 106-120, 164-178` | Validate the theme, call `flush_rewrite_rules()` on the next admin load and factor out the duplicate code. |
| BUG-27 | Func | Plugin activation and deactivation after import write `active_plugins` directly and skip lifecycle hooks. | `functions.php:899-1010` | Deliberate. Document it, and offer an optional "run activation hooks" pass after import. |
| BUG-28 | Func | `ai1wm_cache_flush` removes **all** `sanitize_option_siteurl` and `sanitize_option_home` filters, core's included, for the rest of the request. | `functions.php:1094-1112` | Remove only the specific callbacks and restore them afterwards. |
| BUG-29 | Func | Updater: on a malformed response, `check_for_updates` returns instead of continuing, so nothing is saved. There's no guard for a non-object transient. `tested` is set to the current WP version. The AJAX handler never sends a response. | `lib/model/class-ai1wm-updater.php:65-147`; `lib/controller/class-ai1wm-updater-controller.php:55-81` | Not relevant unless SnapBak ships a paid add-on updater. If it does, use a maintained licensing library. |
| BUG-30 | Func | Script and style gating calls `stripos` with the haystack and needle swapped; it works only by coincidence. | `lib/controller/class-ai1wm-main-controller.php:542, 625, 728` | Compare the `$hook_suffix` stored from `add_menu_page` / `add_submenu_page`. |
| BUG-31 | Func | The report form's submit handler throws a ReferenceError (`e` vs `r`). Feedback, report and delete have no failure handlers, and delete ignores returned errors. | `lib/view/assets/javascript/report.min.js`; `backups.min.js` | Handle errors on every request. |
| BUG-32 | Cosmetic | The Backups permission error checks `AI1WM_BACKUPS_PATH` but prints `AI1WM_STORAGE_PATH`. The import stop message uses the locale key `please_wait_stopping_the_export`. "Maximum upload file size:" has no text domain. The updater "update available" text shows even when there is no update. | `lib/view/backups/index.php`; `lib/controller/class-ai1wm-main-controller.php:618-720`; `lib/view/import/import-buttons.php:61-72` | — |
| BUG-33 | Cosmetic | Dead code: `help-section.php` is never included; `http-authentication.php` and `maintenance-mode.php` are empty; the accordion click handler targets markup that doesn't exist; several constants are unused (`AI1WM_STATUS_FILE`, `AI1WM_ARCHIVE_TOOLS_URL`, `AI1WM_DIRECTORY_INDEX`, `AI1WM_SUPPORT_EMAIL`, `AI1WM_MS_FILES_REWRITING`); deprecated stubs remain. | `lib/view/**`; `constants.php`; `deprecated.php`; `lib/model/class-ai1wm-deprecated.php` | — |
| BUG-34 | Func | The `monthly` cron schedule's interval is computed at call time (`strtotime('+1 month') - time()`), so it drifts between 28 and 31 days. The plugin also overwrites any existing `weekly` or `monthly` schedule. | `lib/controller/class-ai1wm-main-controller.php:929-940` | Use a distinct schedule name. For real monthly runs, schedule single events. |
| BUG-35 | Func | Uninstall deletes only 4 options. `ai1wm_messages`, `ai1wm_updater`, the 16 purchase-key options, the backups directory, `storage/` and the LiteSpeed block in `.htaccess` are all left behind. On multisite, options are deleted only for the current blog. | `uninstall.php:34-41` | Do a full cleanup (with a "keep backups" choice), iterate over the network's sites and remove the `.htaccess` markers. |

---

## 4. Performance bottlenecks

| ID | Bottleneck | Why it hurts | Source | Better approach |
|---|---|---|---|---|
| PERF-01 | Every step is a new HTTP request that re-bootstraps WordPress: browser round-trip in manual mode, non-blocking `wp_remote_post` to itself otherwise. | Full WP load per 10-second slice. Loopbacks fail on hosts that block self-requests, use Basic auth, or have split DNS/TLS. Non-blocking 5 s requests may never be delivered. | `lib/controller/class-ai1wm-export-controller.php:96-111`; import controller (same) | Use Action Scheduler (or WP-Cron) for background jobs, keep a browser-driven runner as a fallback, and run WP-CLI in a single process. Adapt the slice length to `max_execution_time`. |
| PERF-02 | `update_option('ai1wm_status')` runs for **every file** archived or extracted, on an **autoloaded** option. | Thousands of writes to the options table and invalidations of `alloptions`. Painful with object caches and DB replication. | `lib/model/export/class-ai1wm-export-content.php:115`; `lib/model/class-ai1wm-status.php:57-58` | Store job state in a dedicated non-autoloaded option or table, and throttle writes to about one per second. |
| PERF-03 | Status polling every 3 s does a full admin-ajax bootstrap. | Constant load during long jobs. | `lib/view/assets/javascript/*.min.js` | Poll a lightweight REST route with backoff, or return status inside each step response (the browser runner already has it). |
| PERF-04 | Export enumeration walks all of `wp-content` in one request. | Can hit `max_execution_time` or memory limits on large sites. | `lib/model/export/class-ai1wm-export-enumerate.php:82-118` | Enumerate in resumable slices, keeping a directory stack in the job state. |
| PERF-05 | The DB export uses deferred-join pagination that still uses `LIMIT offset,1000`. | Cost grows roughly O(n²) on very large tables. | `lib/vendor/servmask/database/class-ai1wm-database.php:646` | True keyset pagination (`WHERE pk > last ORDER BY pk LIMIT n`). |
| PERF-06 | One INSERT per row. | Large dumps and slow imports. | `lib/vendor/servmask/database/class-ai1wm-database.php` (export) | Multi-row INSERTs sized under `max_allowed_packet`. |
| PERF-07 | Find/replace runs regex callbacks, then unserialize/serialize recursion, on every value; `pcre.backtrack_limit` is set to `PHP_INT_MAX`. | CPU- and memory-heavy, with a risk of catastrophic backtracking on huge rows. | `lib/vendor/servmask/database/class-ai1wm-database.php:1050-1130`; `functions.php:1411-1434` | Do replacement on parsed values, only in text columns, with a quick pre-check (`strpos` for any search term) before parsing. |
| PERF-08 | The archive is uncompressed. | Archives are large and slow to upload. | `lib/vendor/servmask/archiver/class-ai1wm-compressor.php` | Optional per-entry compression (gzip or zstd when available). |
| PERF-09 | `ai1wm_filesize` reads the **whole file** to compute its size (BigInteger, 32-bit support). | O(n) I/O for a size check. | `functions.php:1291-1309` | Require 64-bit PHP and use `filesize()`. |
| PERF-10 | Remote update checks are synchronous inside the `pre_set_site_transient_update_plugins` filter, N requests with a 15 s timeout each. | Can stall admin page loads. | `lib/model/class-ai1wm-updater.php:106-147` | Cache in a transient with a TTL and fetch in the background. |
| PERF-11 | 64 `require_once` calls on **every** request, front end included; `setup_folders()` runs filesystem checks on every admin request. | Wasted work on every page load. | `loader.php`; `lib/controller/class-ai1wm-main-controller.php:222-252` | Autoloading, and lazy-load admin code only on the plugin's own screens or on its jobs. |

---

## 5. Code quality and outdated practices

- **PHP 5.2 baseline** (`readme.txt:4-6`): no namespaces, closures or short arrays; `Math_BigInteger` is bundled only for 32-bit >2 GB support; the legacy `mysql_*` driver class is still present (`lib/vendor/servmask/database/class-ai1wm-database-mysql.php`) even though PHP 7 removed ext/mysql.
- **No autoloader:** every class is loaded by hand in `loader.php`.
- **Static everything:** about 93 static methods. The only instance is `Ai1wm_Main_Controller`, kept in a global. There's no dependency injection, so nothing can be unit-tested.
- **The pipeline is driven by reading `$wp_filter` directly** (`lib/controller/class-ai1wm-export-controller.php:62-117`). It re-implements WP_Hook iteration and depends on WP internals (it already needed a fix for the WP 4.7 change). The request also chooses the pipeline position: `priority` is a client-supplied parameter.
- **Duplication:** the export and import controllers are about 95% identical. The three `enqueue_*` methods repeat the same localisation blocks. `Ai1wm_Extensions::get()` is 16 copy-pasted blocks, and `constants.php` has about 600 lines of per-extension boilerplate.
- **Very large functions:** `Ai1wm_Import_Database::execute` is about 800 lines, and `Ai1wm_Database` is about 1,400.
- **About 90 uses of the `@` error-suppression operator**, combined with a custom error handler that logs even suppressed errors to a public file (`lib/model/class-ai1wm-handler.php`).
- **Template engine:** a vendored micro-engine (Bandar) that uses `extract()` + `include` with static state.
- **Front end:** the `.min.js` files are unminified webpack 3 bundles with no source, no build config and no source maps, and they use jQuery throughout.
- **No tests, no CI, no coding-standards tooling,** and no `composer.json` or `package.json`.
- **Licence mismatch:** file headers say GPLv3-or-later, `readme.txt:8` says GPLv2-or-later.
- **Insecure identifiers:** `rand()`, `uniqid()` and JS `Math.random()` are used where randomness matters.

---

## 6. UX and accessibility gaps

Details and screen references are in `04-UI-UX-Spec.md`.

- **Cancellation:** imports can't be cancelled once processing starts after the upload (no Stop button in the info state). Exports can be stopped.
- **Progress:** the export shows its percentage only inside the message text. The import has a progress bar only during the upload.
- **Session:** the plugin deregisters heartbeat and the login-expiry check (`lib/controller/class-ai1wm-main-controller.php:548-551`), and an import replaces the users table, but nothing tells the user they may need to log in again.
- **After import:** there's no redirect or reload; the user is told to save permalinks twice.
- **Find/replace rows** can be added but not removed.
- **Free plan:** every non-File destination is an outbound upsell link, mixed in with real options without any visual distinction.
- **Accessibility:**
  - No ARIA anywhere.
  - Modals have no `role="dialog"`, focus trap or Esc handling.
  - Expanders are click-only `div`s or `h4`s.
  - One feedback option is reachable only through a hidden radio.
  - Backup action labels are `visibility:hidden` (icon-only for screen readers).
  - Inputs are labelled only by placeholders.
- **i18n:** the text domain loads at `admin_init`, so earlier strings aren't translated. The top-level menu title isn't translated. There's no bundled `languages/` directory. Translated strings embed HTML.
- **Unsupported environments:** on multisite without the paid extension, the plugin shows **no UI at all**, only an upsell notice.

---

## 7. What to do differently (prioritised)

1. **Auth model (fixes SEC-01, 03, 12):** REST API routes under a plugin namespace with a `permission_callback` that checks a dedicated capability (default: `manage_options`, filterable), plus the REST nonce. Background continuation uses per-job HMAC tokens with an expiry, compared with `hash_equals`. No `nopriv` handlers. No secrets in URLs, logs or front-end globals.
2. **Private storage and downloads (SEC-02, 04, 16, 17):** private job and backup directories with deny rules and a reachability self-test; CSPRNG names; authenticated streaming downloads (with range support); `wp_mkdir_p` permissions; a backup index kept server-side.
3. **A safe, verifiable archive format (SEC-05, 06; BUG-02, 04, 20, 21):** a versioned format with a manifest (paths, sizes, mtimes, SHA-256 per file), length-prefixed fields, strict path normalisation on extract, optional compression and optional encryption (libsodium `secretstream` with a password-derived key). Decide separately whether to add a **read-only importer for `.wpress`** (see `01-BRD.md` on legal and branding considerations).
4. **A robust job engine (PERF-01–04; BUG-03, 14, 17, 18):** a job state machine stored outside autoloaded options; every step idempotent and resumable; Action Scheduler or WP-Cron runner with a browser-driven fallback and single-process WP-CLI; time slices derived from `max_execution_time`; throttled progress; `Throwable` handling with guaranteed cleanup; scheduled garbage collection of orphaned jobs.
5. **Correct database migration (SEC-07, 18; BUG-05–13, 23, 24):** structured dump (or multi-row SQL with a proper tokenizer); keyset pagination; identifier-only prefix rewriting; one serialization-aware replacement pass that never instantiates objects; URL variants built from parsed components (scheme, www, encodings, JSON-escaped); views exported correctly; packet-aware inserts; abort and report on SQL errors; restrict executed statements to the target prefix.
6. **Resumable uploads (BUG-04, 15, 16):** offset-addressed chunks, a server-side size and checksum check at the end, `wp_convert_hr_to_bytes`, and a minimum chunk size.
7. **A modern baseline:** PHP ≥ 7.4 (recommend 8.1+), WP ≥ 6.0, 64-bit only (drop BigInteger and the mysql driver), namespaces with Composer PSR-4 autoloading, dependency injection, WordPress Coding Standards with PHPCS and PHPStan in CI, and a JS/CSS build from source (`@wordpress/scripts`, React or vanilla) with source maps.
8. **Privacy and good citizenship (SEC-08, 09, 11, 19; BUG-28, 35):** no third-party scripts; no implicit credential capture; no global `$_SERVER` changes; opt-in telemetry; full uninstall cleanup with a "keep backups" choice; WordPress privacy policy text.
9. **UX and accessibility:** cancellation in every state, a real progress bar with ARIA live regions, accessible dialogs (focus trap, Esc, `role="dialog"`), proper labels, removable find/replace rows, automatic rewrite flush and re-login guidance after import, clear separation of available features from add-ons, and multisite support (at least full-network export and import) instead of a dead end.
10. **Automatic backups in core (fixes BUG-34; covers `01-BRD.md` R-1):** the original's free core can't back anything up automatically, and its monthly schedule drifts. Build a Settings tab with scheduled backups that use one single action per due time, computed from the site's wall-clock time (no drift across months or DST); never run two jobs at once; run missed backups once, not as a backlog; delete old scheduled backups only after a new one succeeds; make a safety backup before every import or restore; send failure e-mails by default. Specified in `02-SRS.md` FR-113 to FR-124 and `04-UI-UX-Spec.md` §15.
11. **Tests:** PHPUnit unit tests for the archive format, the serialized replacer and URL-variant generation; integration tests with `wp-env` for full export→import round trips (single site, multisite, subdirectory installs, HTTPS switch, prefix change); fuzz tests on archive parsing. See `06-Development-Plan.md`.

---

## 8. Open questions

- **BUG-01:** whether `AI1WM_MAX_FILE_SIZE` is a bug in this copy or an intentional local patch. Compare it with a pristine upstream 6.77 zip.
- **PHP_AUTH availability:** whether PHP populates `PHP_AUTH_*` on a given server depends on the SAPI and rewrite rules, and that decides how easily SEC-08 can be triggered.
- **Multisite extension:** its blog-mapping step (`blogs` status) and the `multisite.json` fields are produced by the paid Multisite extension and can't be seen in this code.
- **Session after import:** whether a user's session survives an import depends on whether the imported `wp_users`/`usermeta` contain a matching session token. The original does nothing to preserve sessions (`lib/model/import/class-ai1wm-import-database.php:693-829` restores only URLs, the secret key and auth options).

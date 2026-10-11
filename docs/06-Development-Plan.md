# 06 — Development Plan

How to build SnapBak: a reimplementation of the behaviour documented in `02-SRS.md`, with the problems in `05-Improvement-Notes.md` designed out from the start.

## Contents

1. [Guiding decisions](#1-guiding-decisions)
2. [Recommended architecture](#2-recommended-architecture)
3. [Phased roadmap](#3-phased-roadmap)
4. [Task breakdown and estimates](#4-task-breakdown-and-estimates)
5. [Testing strategy](#5-testing-strategy)
6. [Data migration plan for users switching from the original](#6-data-migration-plan-for-users-switching-from-the-original)
7. [Using the original code as a reference](#7-using-the-original-code-as-a-reference)
8. [Risks and mitigations](#8-risks-and-mitigations)

---

## 1. Guiding decisions

| Decision | Recommendation | Why |
|---|---|---|
| Minimum PHP / WP | PHP 7.4 (target 8.1+ in CI), WordPress 6.0+, 64-bit only | Drops BigInteger, the mysql driver and PHP 5 workarounds (`05-Improvement-Notes.md` §5). |
| Transport | REST API (`/wp-json/snapbak/v1/...`) with capability + nonce; signed per-job tokens for background continuation | Fixes SEC-01/03/12. |
| Job execution | Job state machine. Runners: browser-driven (default), Action Scheduler or WP-Cron background, single-process WP-CLI | Replaces the HTTP loopback chain (PERF-01). |
| Archive format | New versioned format with a manifest and checksums. Add a read-only `.wpress` importer only after legal review (`01-BRD.md`) | Fixes BUG-02/04/20/21 and SEC-05/06. |
| Storage | Private working and backup directories with deny rules, plus a reachability self-test; authenticated downloads | Fixes SEC-02/04. |
| Front end | `@wordpress/scripts` build, React (`@wordpress/components`) or vanilla TS; source committed | Accessibility and maintainability (`04-UI-UX-Spec.md`). |
| Extensibility | Public PHP interfaces for storage destinations and pipeline steps, plus documented hooks | Matches the original's extension model (`03-Technical-Architecture.md`) without depending on `$wp_filter` internals. |

---

## 2. Recommended architecture

### 2.1 Package layout (PSR-4, namespace `SnapBak\`)

```text
snapbak/
├── snapbak.php               # plugin header (Author: Riyadh Mobin), requirements check, boots Plugin
├── uninstall.php
├── composer.json             # PSR-4 "SnapBak\\": "src/"
├── src/
│   ├── Plugin.php            # service container wiring, hook registration
│   ├── Admin/                # menu pages, asset enqueue, notices
│   ├── Rest/                 # controllers: Jobs, Backups, Uploads, Status
│   ├── Job/                  # Job, JobRepository, JobRunner(s), Step interface, StepResult
│   ├── Export/Steps/         # Init, Enumerate, Content, Database, Finalize, Cleanup
│   ├── Import/Steps/         # Receive, Validate, Confirm, Extract, Database, Finalize, Cleanup
│   ├── Archive/              # Writer, Reader, Manifest, PathGuard, (Legacy/WpressReader)
│   ├── Database/             # Dumper, Importer, SqlTokenizer, Replacer, SerializedReplacer, UrlVariants
│   ├── Storage/              # Filesystem paths, ProtectedDirectory, Backups index, Download streamer
│   ├── Destination/          # DestinationInterface, LocalFile (core), registry
│   ├── Security/             # Capabilities, JobToken (HMAC), Redactor
│   ├── Settings/             # Settings schema (defaults, ranges, enums), repository, REST controller
│   ├── Schedule/             # Scheduler (next-run calculation, single actions), retention, safety backups, update hook
│   ├── Cli/                  # WP-CLI commands
│   └── Support/              # Logger (PSR-3 style), Clock, Settings
├── assets/src/               # JS/TS + SCSS sources → assets/build/
├── templates/                # minimal PHP admin shells (React mounts here)
├── languages/
└── tests/ (unit/, integration/, e2e/, fixtures/)
```

### 2.2 Core concepts

- **Job:** ID (random 128-bit), type (export/import), state (`queued → running → awaiting_confirmation → running → completed | failed | cancelled`), current step, a per-step cursor (offsets), options, counters, and timestamps. It is stored in a non-autoloaded option per job, or in a small custom table (`{prefix}snapbak_jobs`) if you want history. A table is recommended once scheduling arrives.
- **Step interface:** `run(Job, Deadline): StepResult`, where the result is `done`, `continue` (with an updated cursor) or `needs_input` (for confirmation). The step order is a registry, not WordPress filter priorities. The documented actions `snapbak_export_steps` and `snapbak_import_steps` let add-ons insert steps by name, before or after another step.
- **Runners:**
  - *Browser:* the UI calls `POST /jobs/{id}/tick` repeatedly; each tick runs steps until the deadline passes.
  - *Background:* Action Scheduler enqueues the next tick.
  - *CLI:* loops in-process with no deadline.
  - All three share one engine.
- **Deadline:** `min(20s, 0.6 × max_execution_time)`, filterable. Replaces the fixed 10 s `ai1wm_completed_timeout`.
- **Progress:** in-memory counters, written at most once per second; returned in every tick response and by `GET /jobs/{id}`.
- **Settings and schedules:** one schema class defines every setting (type, default, range, enum, cross-field rules); the REST controller, the Settings tab and `wp snapbak config` all validate against it. The scheduler keeps exactly one pending single action (`snapbak_scheduled_backup`) and computes each next run from the wall-clock time in `wp_timezone()`, so monthly runs and DST changes don't drift. Every automatic backup is an ordinary export job with a `type` (`scheduled` or `safety`), so progress, logs, notifications and retention reuse the job engine.
- **Destinations:** a `DestinationInterface` with upload, download, list and delete. Core ships `LocalFile`. Add-ons register cloud destinations, which take the place of the original's 14 `ai1wm_export_*` button filters.

### 2.3 REST surface (replaces the admin-ajax actions)

| Route | Method | Purpose | Replaces |
|---|---|---|---|
| `/jobs` | POST | Create an export or import job (options, find/replace) | `ai1wm_export` / `ai1wm_import` with priority 5 |
| `/jobs/{id}` | GET | Status and progress | `ai1wm_status` |
| `/jobs/{id}/tick` | POST | Advance the job (browser runner) | the JSON echo loop |
| `/jobs/{id}/confirm` | POST | Confirm a destructive import | priority 150 confirm |
| `/jobs/{id}` | DELETE | Cancel the job and clean up | priority 300 / 400 stop |
| `/uploads/{id}` | PUT | Offset-addressed chunk upload (`Content-Range`) | `upload-file` append |
| `/backups` | GET | List backups | Backups page render |
| `/backups/{id}` | DELETE | Delete a backup | `ai1wm_backups` |
| `/backups/{id}/download` | GET | Authenticated streaming download (supports Range) | direct public URL |
| `/backups/{id}/restore` | POST | Create an import job from an existing backup | `ai1wm_manual_restore` |
| `/settings` | GET, PUT | Read and save all settings (schema-validated; the password is never returned) | none (new) |
| `/schedule/run` | POST | "Run scheduled backup now": create a `scheduled` export job | none (new) |
| `/settings/test-email` | POST | Send a test notification e-mail | none (new) |

Every route has a `permission_callback` that checks the plugin capability. Background ticks are authenticated with `JobToken` (an HMAC of the job ID and expiry, keyed with `wp_salt('auth')`).

---

## 3. Phased roadmap

Estimates assume one senior WordPress developer, and are in ideal engineering days (d). The ranges reflect real uncertainty, mainly in database replacement and host compatibility.

| Phase | Goal | Exit criteria | Estimate |
|---|---|---|---|
| **0. Foundations** | Repo, tooling, CI, plugin skeleton, reference policy and licence decision | CI green on PHP 7.4–8.3 × WP 6.0–latest; PHPCS/PHPStan level 6; `wp-env` working | 4–6 d |
| **1. MVP: single-site export and import to file** | Feature parity for the core free flow | Round-trip a reference site (posts, media, plugins, themes, serialized options) to a new domain and new table prefix with zero diff in the verification script. Archives up to 5 GB on a host with a 2 MB upload limit and 30 s `max_execution_time`. | 30–40 d |
| **2. Feature parity** | Everything else the original's free core does | Backups page (list, download, delete, restore); every export exclusion option; find/replace UI; WP-CLI backup and restore; notices and self-tests; i18n; uninstall; host compatibility list (LiteSpeed, IIS, Nginx) | 18–25 d |
| **3. Improvements** | Things the original lacks or gets wrong | Background runner (Action Scheduler); **Settings tab with scheduled backups, retention and safety backups** (before import/restore and before automatic updates); email notifications; encryption; compression; accessible UI audit (WCAG 2.1 AA); multisite full-network export/import; optional read-only legacy `.wpress` importer | 31–49 d |
| **4. Add-on platform (optional / commercial)** | Destination add-ons | `DestinationInterface` stable; first add-on (e.g. S3-compatible, covering S3, DigitalOcean, B2 and others); licensing/updates through a maintained library | 15–25 d per first add-on, then 5–10 d each |

Total to feature parity (phases 0–2): about **52–71 days**. With phase 3: about **83–120 days**.

---

## 4. Task breakdown and estimates

SRS references point at the functional-requirement groups in `02-SRS.md`.

### Phase 0 — Foundations (4–6 d)

| # | Task | Est. |
|---|---|---|
| 0.1 | Repo, `composer.json` (PSR-4), PHPCS (WPCS), PHPStan, PHPUnit, `wp-env`, GitHub Actions matrix | 1.5 d |
| 0.2 | JS toolchain (`@wordpress/scripts`), lint and test (Jest), build artifacts excluded from git and included in releases | 1 d |
| 0.3 | Plugin bootstrap: requirements check, service container, capability registration, activation and uninstall skeleton | 1 d |
| 0.4 | Reference test sites and fixture generator (see §5.3) | 1–2 d |
| 0.5 | Reference policy (§7): `CREDITS.md`, pull-request template field "Original code consulted (file:line)", licence decision (GPLv2+ or GPLv3+) | 0.5 d |

### Phase 1 — MVP (30–40 d)

| # | Task | SRS area | Est. |
|---|---|---|---|
| 1.1 | Job model, repository, state machine, deadline, StepResult; browser runner; REST `/jobs`, `/tick`, `/jobs/{id}`, cancel | pipeline, status, stop/cancel | 4–5 d |
| 1.2 | Security layer: capability, nonce, JobToken, log redaction | security NFRs | 1.5 d |
| 1.3 | Protected storage: private dirs, deny files (Apache/IIS), reachability self-test with an admin notice, random names, `wp_mkdir_p` | bootstrap / folder protection | 2 d |
| 1.4 | Archive format v1: writer (streamed, resumable, per-file SHA-256, manifest), reader, PathGuard, format spec doc, fuzz tests | archive format | 5–6 d |
| 1.5 | Export enumeration (resumable directory walk, exclusions, symlink policy) | export enumerate | 2 d |
| 1.6 | Export content step (resumable chunked copy into the archive) | export content | 1.5 d |
| 1.7 | DB dumper: table discovery by prefix, keyset pagination, multi-row inserts sized to `max_allowed_packet`, views, placeholder prefix | DB dump | 4–5 d |
| 1.8 | Upload: offset-addressed chunks, resume after reload, size and checksum verification, `wp_convert_hr_to_bytes` | chunked upload | 2–3 d |
| 1.9 | Import validate and confirm (manifest, version and compat checks, disk-space estimate, confirmation state) | validate / confirm | 1.5 d |
| 1.10 | Import extract (resumable, PathGuard, skip list: drop-ins, own plugin, backups dir) | content extraction | 2 d |
| 1.11 | DB importer: tokenizer, identifier-only prefix rewrite, statement allow-list, error handling | DB import | 3–4 d |
| 1.12 | Replacement engine: URL variant builder (scheme, www, encodings, JSON), serialized replacer that doesn't instantiate objects, upload paths, emails, user pairs | find/replace & serialization | 4–5 d |
| 1.13 | Import finalize: preserve the target's siteurl/home, the plugin's own state and its active status; theme validation; rewrite flush on next load; session/re-login handling | import done | 1.5 d |
| 1.14 | Admin UI: Export screen (options, find/replace rows with remove), Import screen (drop zone, progress), accessible modal or state panel | UI (`04-UI-UX-Spec.md`) | 4–5 d |

### Phase 2 — Feature parity (18–25 d)

| # | Task | SRS area | Est. |
|---|---|---|---|
| 2.1 | Backups index, list UI, authenticated streaming download (Range), delete, restore-from-backup | backups | 3–4 d |
| 2.2 | All export exclusion options (spam comments, revisions, media, themes, inactive themes, mu-plugins, plugins, inactive plugins, cache, database, email-domain replace) with correct boolean parsing and multisite-correct exclusions | export options | 2–3 d |
| 2.3 | Host-specific import handling (deactivate known-problematic plugins and mu-plugins such as SSL forcers, login hiders and host cache drop-ins), driven by a filterable list | import mu-plugins / done | 1.5 d |
| 2.4 | WP-CLI: `backup`, `restore`, `list`, `delete`, with flags matching the export options and strict `--replace` pairs | WP-CLI | 2–3 d |
| 2.5 | Admin notices and Site Health tests (dirs writable, storage reachable, loopback/background availability, disk space, PHP limits) | notices | 1.5 d |
| 2.6 | Logging: per-job log viewer, redaction, rotation, download log | logging | 1.5 d |
| 2.7 | i18n: text domain, JS translations (`wp_set_script_translations`), POT generation | i18n NFR | 1 d |
| 2.8 | Uninstall with a "keep backups" option; network-wide cleanup | uninstall | 1 d |
| 2.9 | Compatibility test matrix run (Apache, Nginx, LiteSpeed, IIS if available; MySQL 5.7/8, MariaDB 10.x; subdirectory installs; HTTPS changes) and fixes | compatibility NFR | 3–5 d |
| 2.10 | Docs: user guide, developer hooks reference, archive format spec | — | 1.5 d |

### Phase 3 — Improvements (25–40 d)

| # | Task | Est. |
|---|---|---|
| 3.1 | Background runner on Action Scheduler; resume after a failed tick; stale-job garbage collector | 3–4 d |
| 3.2 | Encryption (libsodium secretstream, Argon2id key from a password); encrypted manifest flag | 3–4 d |
| 3.3 | Compression (per-entry gzip, zstd if available) | 2 d |
| 3.4 | Scheduled backups (FR-114 to FR-118): configuration, single-action scheduling with DST-safe next-run calculation, overlap deferral, missed-run handling, retention, "Run now", status, Site Health test. Needs 3.1 and 3.10 first | 4–6 d |
| 3.5 | Email notifications (FR-086, FR-121): failure on and success off by default, recipients, test e-mail | 1–1.5 d |
| 3.6 | Multisite: network admin UI, full-network export/import, subsite-to-single-site export (scope to decide) | 8–12 d |
| 3.7 | Read-only legacy `.wpress` importer (only after legal sign-off), built from the format description in `03-Technical-Architecture.md` | 3–5 d |
| 3.8 | Accessibility audit and fixes (WCAG 2.1 AA), RTL check | 2–3 d |
| 3.9 | Performance work: parallel-safe chunking, a faster replacer pre-filter, a benchmark suite | 2–4 d |
| 3.10 | Settings tab and settings API (FR-113, FR-123): schema, `GET`/`PUT /settings`, form with error summary and inline errors, unsaved-changes state, Advanced section (runner, encrypted Basic-auth credentials). Do this before 3.4 | 2–3 d |
| 3.11 | Safety backups (FR-119, FR-120): step before destructive import/restore steps, disk-space check and choice dialog, preserve the backups index across the import, `pre_auto_update` hook, keep-N for safety backups | 2–3 d |
| 3.12 | Storage-usage warning and backup type labels (FR-122, FR-124): status list, daily warning e-mail, `type` in index, manifest, Backups tab and `wp snapbak list` | 1 d |

---

## 5. Testing strategy

### 5.1 Automated

| Level | Tooling | What it covers |
|---|---|---|
| Unit | PHPUnit (with Brain Monkey or WP_Mock for pure units) | Archive writer/reader round-trip; PathGuard (`..`, absolute paths, Windows drive letters, symlinks); manifest checksum mismatch; SQL tokenizer (escaped quotes, backslashes, multi-line, binary); serialized replacer (nested arrays, objects, incomplete classes, length changes, UTF-8 multibyte); URL-variant builder (http/https/`//`, www, subdirectory, port, IDN, JSON-escaped, url-encoded); size parsing; JobToken expiry and tamper; redactor; next-run calculation (every frequency, day 28/29-31 edge, DST forward/back in `Europe/Berlin` and `America/New_York`, UTC+06:00); retention selection (only `scheduled`, only after success); settings schema validation |
| Integration | `wp-env` + PHPUnit (WP test suite) | REST permissions (anonymous, subscriber, admin, wrong nonce); full export→import round trip on fixture sites; resume after a simulated timeout at every step boundary (deadline = 0.1 s); cancel at every step; idempotent replays of the same tick; upload chunk replay and out-of-order chunks; uninstall cleanup |
| Fuzz | PHPUnit data providers + random archives | Truncated or garbled archive headers; oversize fields; random SQL lines |
| E2E | Playwright (`@wordpress/e2e-test-utils-playwright`) | Export to download; drag-and-drop import with confirmation; restore from Backups; delete; keyboard-only run-through; screen-reader live-region announcements (axe checks) |
| Static | PHPCS (WPCS), PHPStan, ESLint, Stylelint | Coding standards, types, escaping sniffs |
| Matrix | GitHub Actions | PHP 7.4, 8.1, 8.2, 8.3 × WP 6.0, latest, nightly × MySQL 5.7, 8.0, MariaDB 10.6/11 |

### 5.2 Migration verification script

A WP-CLI command (dev-only) that fingerprints a site: row counts and checksums per table with the prefix normalised, a URL census (no leftover old-domain URLs outside a configured allow-list), a file tree hash for `wp-content` minus exclusions, active plugins and theme, and `siteurl`/`home`. Run it on the source and on the target after import; the diff must be empty apart from expected changes.

### 5.3 Fixture sites

1. A vanilla site with sample content.
2. A "serialized-heavy" site: page-builder data (Elementor JSON, Visual Composer base64 as in the original's special cases), widget options, and transients containing URLs.
3. A large site: 50k posts, 20k media files, 5 GB uploads, a table without a primary key, a view, and a non-WP table sharing the prefix.
4. A subdirectory install plus HTTPS change plus a custom table prefix.
5. Multisite (subdomain and subdirectory), for phase 3.

### 5.4 Manual QA checklist

- [ ] Fresh install: no PHP notices with `WP_DEBUG` on; menus appear for admins only; storage self-test passes, or shows an actionable notice.
- [ ] Export with default options; the archive downloads only when logged in, and its direct URL returns 403/404.
- [ ] Export with each exclusion option individually; verify the archive's contents.
- [ ] Find/replace: add 3 rows, remove 1; the values are applied at import.
- [ ] Import a 2 GB archive with `upload_max_filesize=2M`; refresh the browser mid-upload; the upload resumes.
- [ ] Import to a different domain, scheme and table prefix; run the verification script; log in again and confirm that permalinks work without a manual save.
- [ ] Cancel during upload, during extraction and during the database step; the site is left in a consistent state (documented), and temporary files are removed.
- [ ] Kill PHP mid-step (restart php-fpm); the job resumes or fails cleanly with a message.
- [ ] Corrupted archive, wrong file type, archive with `../` entries: all rejected with a clear message and nothing written.
- [ ] Insufficient disk space: the user is warned before starting.
- [ ] WP-CLI `backup` and `restore` on the same fixture sites; exit codes are correct.
- [ ] Keyboard-only and screen-reader pass over all screens; RTL locale; one non-English locale.
- [ ] Uninstall with and without "keep backups"; no options, directories or `.htaccess` markers left behind.
- [ ] Settings: save with invalid values shows the error summary and inline errors and saves nothing; valid save shows the next run on the Settings and Backups tabs.
- [ ] Scheduled backups: daily schedule runs at the set time with a real cron job; with `DISABLE_WP_CRON` and no cron job the warning appears; an import running at the due time defers the backup; after `keep` + 1 runs exactly `keep` scheduled backups remain and manual backups are untouched.
- [ ] Safety backup: an import creates a "Safety" backup first; restoring it returns the site to its pre-import state; with too little disk space the import asks before continuing.
- [ ] Notifications: a forced scheduled-backup failure sends one e-mail; "Send a test email" works and reports failure when mail is broken.
- [ ] Hosts: Apache + mod_php, Nginx + php-fpm, LiteSpeed, a shared host with loopback blocked.

---

## 6. Data migration plan for users switching from the original

There is no persistent plugin data worth migrating: the original stores only operational options (`ai1wm_secret_key`, `ai1wm_status`, the auth options, the updater cache and purchase keys). See `02-SRS.md` (settings reference). What users do have is **existing `.wpress` backups**.

| Item | Plan |
|---|---|
| Existing `.wpress` archives in `wp-content/ai1wm-backups/` | **Option A (recommended for MVP):** document the path. Users restore with the original plugin one last time, then create a new backup with SnapBak. **Option B (phase 3.7, after legal review):** a read-only legacy importer that maps `.wpress` entries (header layout, `package.json`, `database.sql` with the `SERVMASK_PREFIX_` placeholder) onto the new import pipeline. It must apply the same PathGuard and SQL restrictions. |
| Find/replace pairs stored in `package.json` of old archives | Read by the legacy importer and shown on the confirm screen. |
| Options left in `wp_options` by the original | Not read or migrated. Offer an optional "clean up the old plugin's data" tool that lists `ai1wm_*` options and, after confirmation, deletes them. Never touch them automatically. |
| The original's `.htaccess` LiteSpeed block (`# BEGIN LiteSpeed … SetEnv noabort 1`) | Leave it alone. If SnapBak needs the same thing, use its own marker name. |
| Running both plugins side by side | Supported: different slugs, option prefixes, directories and REST namespaces. Warn on the confirm screen if the archive being imported would overwrite the other plugin's directory. |

---

## 7. Using the original code as a reference

The documents in `docs/` are the specification. The original plugin's source (`all-in-one-wp-migration/`) **may be consulted as a reference** when a document is unclear or incomplete, or to check an edge case. Every requirement in `02-SRS.md` has a "Source" row with `file:line` pointers for this. The rules below keep SnapBak independent, secure and correctly licensed.

1. **Docs first.** Start from the requirement. Open the original only to answer a specific question, such as an exact replacement order, a host quirk or an archive field size. Where the original and the docs disagree, the docs win: they deliberately fix the original's behaviour (each FR's "Original behaviour" row).
2. **Check `05-Improvement-Notes.md` before reusing any idea.** Much of the original is insecure or buggy (SEC-01 to SEC-19, BUG-01 to BUG-35) or built for PHP 5.2. Don't carry over its architecture: no `nopriv` endpoints, no secret key, no `$wp_filter` walking, no static-only classes, no public backup folder.
3. **Write SnapBak's own code.** Don't copy files or large blocks. If a small piece of logic is adapted, for example a regex or a list of host-specific plugins to deactivate:
   - mark it in the file header ("Portions adapted from All-in-One WP Migration by ServMask, GPL") and in `CREDITS.md`;
   - mention it in the pull request;
   - apply the licence rule in item 6.
4. **New names everywhere:** the product is **SnapBak** by **Riyadh Mobin**. Use slug and text domain `snapbak`, namespace `SnapBak\`, prefixes `snapbak_` / `SNAPBAK_`, REST namespace `snapbak/v1`, CLI `wp snapbak`, CSS prefix `snapbak-`, archive extension `.snapbak` with the `SNAPBAK` signature, and UI copy written from scratch (full list in `02-SRS.md` §1.6). No ServMask or "All-in-One WP Migration" branding, icons or `ai1wm` identifiers (`01-BRD.md` §6.3). The only exception is the optional legacy `.wpress` reader (FR-043).
5. **Separate repository.** SnapBak lives in its own repository. The original stays in this reference repository, read-only, and is never copied into or bundled with SnapBak. Developers and AI assistants may have read access to it.
6. **Licensing.** SnapBak is **GPLv2 or later** as long as it contains no code from the original. The original's file headers say GPLv3-or-later (`readme.txt` says GPLv2-or-later; treat it as GPLv3-or-later). As soon as any adapted code is included, SnapBak must be distributed as **GPLv3 or later**, with the attribution from item 3. This is not legal advice; have it reviewed before a commercial launch.
7. **Record keeping.** When the original was consulted for a change, link the `file:line` in the pull request. This keeps decisions traceable and makes the licence status in item 6 easy to verify.

---

## 8. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Serialized and encoded URL replacement misses edge cases (page builders, JSON in meta, base64) | High | High: broken sites after migration | Fixture site 2; verification script URL census; pluggable replacer hooks for builder-specific data |
| Host limits (no loopback, short timeouts, low memory, upload caps, WAF blocking chunk uploads) | High | High | Browser runner by default; adaptive deadlines and chunk sizes; Site Health tests; documented host matrix |
| Very large sites (≥ 20 GB, millions of rows) | Medium | High | Streaming everywhere, keyset pagination, resumable steps, benchmarks in phase 3.9; recommend WP-CLI above a threshold |
| Partial import leaves the site broken (DB half-imported) | Medium | High | Import into temporary prefixed tables, then swap with atomic `RENAME TABLE`; keep the old tables until success, then drop them (or keep them as a rollback option) |
| Users lose their session after import | High | Medium | Expected behaviour; guide the user to log in again with the source site's credentials, and say so on the confirm screen |
| Legal: trademark or copyright claims; `.wpress` compatibility | Low–Med | High | §7 reference policy (no copied branding, attribution and licence rule for adapted code); legal review before shipping the legacy importer or marketing comparisons |
| Scope creep into cloud destinations before the core is solid | Medium | Medium | Phases 0–2 are gated on the round-trip exit criteria; destinations are phase 4 |
| Multisite complexity | Medium | Medium | Defer to phase 3; make the single-site product excellent first; show an honest "not supported yet" message instead of a dead end |

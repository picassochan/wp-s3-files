# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.3] - 2026-04-28

### Added

- **Debug mode** — new `debug` setting (default off). When enabled, logs all S3 requests/responses (URL, headers, payload size, elapsed time, status code, response body prefix), offload pipeline events (queue, start, complete, skip), URL filter replacements, and remote delete operations.
- `WPS3F_Logger::debug()` — writes to both PHP `error_log` and a dedicated `wps3f_debug_log` WP option buffer (max 200 entries), only when debug mode is active.
- `WPS3F_S3_Client::test_connection()` — new method for admin connectivity testing.
- **Debug Panel** on the settings page with four sections:
  - Configuration Snapshot — read-only view of all settings (access key partially masked).
  - WP-Cron Status — shows next scheduled run for offload, migration, and backfill hooks.
  - S3 Connection Test — button that calls `test_connection()` and displays pass/fail.
  - Debug Log — last 100 debug entries with timestamp, message, and JSON context; includes a "Clear Debug Log" button.
- `admin_post_wps3f_clear_debug_log` action for clearing the debug log buffer.
- `WPS3F_DEBUG` wp-config.php constant fallback for the debug setting.

### Changed

- Renamed `.omx` references to `.omc` (Oh My OpenCode working directory) in `.gitignore` and CI workflow.
- `WPS3F_Admin` constructor now receives `WPS3F_S3_Client` for connection testing.
- Region is now optional — can be left empty for S3-compatible services (e.g. MinIO) that do not use regions. Endpoint becomes required when Region is empty. Default region changed from `us-east-1` to empty.

## [0.1.2] - 2026-04-28

### Added

- Storage classification backfill service (`WPS3F_Storage_Backfill_Service`) for tagging existing attachments with `_wps3f_is_s3` / `_wps3f_has_local_copy` flags in background batches of 50.
- Admin UI section for storage classification backfill with start, stop, and retry-failed controls.
- `_wps3f_is_s3` and `_wps3f_has_local_copy` post meta on every attachment, used for Media Library filtering.
- `WPS3F_Offloader::refresh_storage_flags()` and `WPS3F_Offloader::get_storage_flags()` helpers.

### Changed

- Media Library filters (`upload.php` list view and modal) now query by `_wps3f_is_s3` / `_wps3f_has_local_copy` meta instead of `_wps3f_state`.
- Default Media Library scope is now S3 (previously showed all attachments).

## [0.1.1] - 2026-04-28

### Added

- Inline JavaScript storage filter for the WordPress media modal (post editor), adding S3 / Local / All dropdown.
- `WPS3F_Media_Library::enqueue_media_modal_filter_assets()` — injects filter UI on `post.php`, `post-new.php`, and `upload.php`.
- `ajax_query_attachments_args` filter to apply storage meta query to modal AJAX requests.

### Changed

- Refactored Media Library integration into `WPS3F_Media_Library` class (extracted from admin).
- Storage column now shows "S3 + Local" when both flags are true.

## [0.1.0] - 2026-04-28

### Added

- Core asynchronous offload engine: queue via WP-Cron, upload with 3 retries and exponential back-off.
- Custom S3 client (`WPS3F_S3_Client`) with self-implemented AWS Signature Version 4 signing — no external SDK dependency.
- Support for S3-compatible storage providers via custom endpoint configuration.
- URL replacement filters for `wp_get_attachment_url`, `wp_get_attachment_image_src`, and `wp_calculate_image_srcset`.
- Upload of all attachment files: original image, `original_image` (if present), and all registered image sizes.
- Configurable local backup retention (`keep_local_backup`).
- Configurable remote delete sync (`delete_remote_on_delete`).
- File size limit (`max_offload_size_mb`, default 200 MB) — oversized files stay local.
- Custom CDN domain support (`custom_domain`).
- `WPS3F_Options` with DB-first, `wp-config.php` constant fallback, and full input sanitization.
- `WPS3F_Logger` — dual output to PHP `error_log` and WP option buffer (max 50 entries).
- `WPS3F_Key_Builder` — S3 object key construction with path normalization and per-segment URL encoding.
- Admin settings page under **Settings > WP S3 Files** with all configuration fields.
- Historical migration tool with WP-Cron batch processing (20 per batch, 30-second intervals).
- Failed attachment retry: bulk retry from migration UI and per-attachment retry links.
- Recent error log viewer on the settings page (last 20 entries).
- Admin notice showing the latest error with a link to the settings page.
- Storage status column on the Media Library list screen (`upload.php`).
- Storage filter dropdown on the Media Library list screen (`upload.php`).
- Plugin activation hook: ensures option exists with defaults (autoload off).
- Plugin deactivation hook: clears all scheduled WP-Cron events.
- GitHub Actions workflow: auto-tag, build zip, and create Release on push to main.
- plugin-update-checker integration for auto-updates from GitHub Releases.
- i18n support with English and Simplified Chinese (`zh_CN`) translations.

## [0.0.1] - 2026-04-28

### Added

- Initial project scaffolding.

[0.1.3]: https://github.com/picassochan/wp-s3-files/releases/tag/0.1.3
[0.1.2]: https://github.com/picassochan/wp-s3-files/releases/tag/0.1.2
[0.1.1]: https://github.com/picassochan/wp-s3-files/releases/tag/0.1.1
[0.1.0]: https://github.com/picassochan/wp-s3-files/releases/tag/0.1.0
[0.0.1]: https://github.com/picassochan/wp-s3-files/releases/tag/0.0.1

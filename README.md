# WP S3 Files

WordPress plugin that offloads Media Library attachments to S3-compatible object storage.

## Features

- **Asynchronous offload** — uploads are queued via WP-Cron after attachment creation, keeping the editor non-blocking.
- **Full attachment coverage** — images, documents, audio, video; includes all registered image sizes and the original image.
- **Transparent URL replacement** — `wp_get_attachment_url`, `wp_get_attachment_image_src`, and `wp_calculate_image_srcset` filters automatically serve S3 / CDN URLs.
- **Upload retry** — failed uploads are retried up to 3 times with exponential back-off; remaining failures stay local and can be retried from the admin UI.
- **Local backup control** — optionally keep local files after offload (default: off).
- **Delete sync** — optionally delete remote objects when an attachment is deleted in WordPress (default: on).
- **Historical migration** — one-click batch migration for existing attachments via WP-Cron (20 per batch).
- **Storage classification backfill** — background task that tags every attachment with `_wps3f_is_s3` / `_wps3f_has_local_copy` flags.
- **Media Library filters** — dropdown filter (`All / S3 / Local`) on the upload list screen and inside the media modal, defaulting to S3 scope.
- **Storage status column** — shows "S3", "S3 + Local", "Local", or "—" in the media list table.
- **Error log viewer** — recent errors displayed on the settings page with timestamp, error code, message, and attachment ID.
- **wp-config.php constant fallbacks** — DB settings take priority; constants are only used when the corresponding DB field is not set.
- **Auto-update** — GitHub Releases integration via plugin-update-checker.
- **i18n** — English and Simplified Chinese translations included.

## Requirements

- WordPress 5.3+
- PHP 7.4+
- An S3-compatible object storage service (AWS S3, MinIO, Cloudflare R2, etc.)
- WP-Cron must be functional (or a custom cron runner)

## Installation

1. Download the latest release zip from [GitHub Releases](https://github.com/picassochan/wp-s3-files/releases).
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin** and upload the zip.
3. Activate **WP S3 Files**.
4. Open **Settings > WP S3 Files** and configure your storage credentials.

Alternatively, clone or copy the plugin folder into `wp-content/plugins/wp-s3-files/` and activate it.

## Settings

Navigate to **Settings > WP S3 Files**.

| Setting | Key | Default | Description |
|---|---|---|---|
| Enable Offload | `enabled` | `1` | Master switch for automatic S3 offload. Automatically disabled if bucket is empty. |
| Bucket | `bucket` | `""` | S3 bucket name. |
| Region | `region` | `us-east-1` | S3 region. |
| Endpoint | `endpoint` | `""` | Custom endpoint URL. Leave empty for AWS default. For S3-compatible providers, set a full URL (e.g. `https://minio.example.com`). |
| Access Key | `access_key` | `""` | S3 access key. |
| Secret Key | `secret_key` | `""` | S3 secret key. Masked in the form. |
| Custom CDN Domain | `custom_domain` | `""` | Optional. If set, public URLs use this domain instead of the S3 endpoint. Example: `https://cdn.example.com`. |
| Path Prefix | `path_prefix` | `wp-content/uploads` | Prefix prepended to object keys. |
| Max Offload File Size (MB) | `max_offload_size_mb` | `200` | Files exceeding this limit stay local and are marked as failed. |
| Keep Local Backup | `keep_local_backup` | `0` | When enabled, local files are preserved after successful offload. |
| Delete Sync | `delete_remote_on_delete` | `1` | When enabled, remote S3 objects are deleted when the attachment is deleted in WordPress. |

### wp-config.php Constant Fallbacks

Database settings saved from the admin UI take priority. Constants are only used when the corresponding DB field is not set.

```php
define('WPS3F_ENABLED', true);
define('WPS3F_BUCKET', 'your-bucket');
define('WPS3F_REGION', 'us-east-1');
define('WPS3F_ENDPOINT', 'https://s3.us-east-1.amazonaws.com');
define('WPS3F_ACCESS_KEY', 'AKIA...');
define('WPS3F_SECRET_KEY', '...');
define('WPS3F_CUSTOM_DOMAIN', 'https://cdn.example.com');
define('WPS3F_KEEP_LOCAL_BACKUP', false);
define('WPS3F_DELETE_REMOTE_ON_DELETE', true);
define('WPS3F_PATH_PREFIX', 'wp-content/uploads');
define('WPS3F_MAX_OFFLOAD_SIZE_MB', 200);
```

## Admin Tools

The settings page includes three admin tools:

### Historical Migration

Batch-migrates all existing attachments to S3. Runs via WP-Cron in batches of 20 with 30-second intervals. Shows progress (processed / total) and a list of failed attachment IDs with individual retry links.

### Storage Classification Backfill

Scans all attachments and sets `_wps3f_is_s3` / `_wps3f_has_local_copy` meta flags. Runs in batches of 50. Useful after plugin activation on a site with existing media.

### Error Log

Displays the 20 most recent errors with timestamp, error code, message, and attachment ID. The latest error is also shown as a dismissible admin notice across the dashboard.

## Media Library Integration

- **List view** (`upload.php`): a "Storage" column shows S3 / S3 + Local / Local status. A dropdown filter narrows by storage type (defaults to S3).
- **Modal** (post editor media picker): the same S3 / Local / All filter is injected into the media modal toolbar via inline JavaScript.
- **AJAX queries**: the `ajax_query_attachments_args` filter applies the storage meta query to media modal searches.

## Object Key Layout

Object keys follow the pattern:

```
{path_prefix}/{year}/{month}/{filename}
```

Example: `wp-content/uploads/2026/04/photo.jpg`

Image sizes are stored as separate objects with the same prefix:

```
wp-content/uploads/2026/04/photo-300x200.jpg
wp-content/uploads/2026/04/photo-1024x768.jpg
```

## Attachment Metadata

The plugin stores the following post meta on each attachment:

| Meta Key | Description |
|---|---|
| `_wps3f_state` | `pending`, `offloaded`, or `failed` |
| `_wps3f_objects` | Serialized array of uploaded S3 objects (original + sizes), with key, url, and etag per object. |
| `_wps3f_error` | Last error message (cleared on successful offload). |
| `_wps3f_is_s3` | `1` if the attachment has been offloaded to S3, `0` otherwise. |
| `_wps3f_has_local_copy` | `1` if a local file still exists, `0` if deleted after offload. |

## Limitations (V1)

- **Single-request PUT only** — no multipart upload. Files larger than `max_offload_size_mb` remain local.
- **No signed/private URLs** — all S3 objects are assumed to be publicly readable (or served via a CDN).
- **No S3 download** — the plugin uploads and deletes only; it does not download objects back from S3.
- **No object listing** — the plugin does not call S3 ListObjects.

## License

GPL-2.0-or-later

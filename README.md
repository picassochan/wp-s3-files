# WP S3 Files

WordPress plugin that offloads Media Library attachments to S3-compatible object storage.

## Features (V1)

- Asynchronous offload with WP-Cron after attachment creation.
- Covers all attachment types (images, documents, audio, video).
- Keeps WordPress content URLs on S3 object URLs by default.
- Local fallback on upload failure (non-blocking editor workflow).
- Configurable local backup retention (default: disabled).
- Configurable remote delete sync on attachment delete (default: enabled).
- Historical migration tool (batch migration + retry failed items).
- Online update integration via bundled `plugin-update-checker`.

## Installation

1. Copy this plugin folder into `wp-content/plugins/wp-s3-files`.
2. Activate **WP S3 Files** in WordPress Admin.
3. Open **Settings > WP S3 Files** and configure storage credentials.

## Settings

- `enabled`
- `bucket`
- `region`
- `endpoint`
- `access_key`
- `secret_key`
- `custom_domain`
- `keep_local_backup`
- `delete_remote_on_delete`
- `path_prefix` (default: `wp-content/uploads`)
- `max_offload_size_mb` (default: `200`)

## Constant Overrides (`wp-config.php`)

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

## Online Updates (`plugin-update-checker`)

Set the update source in `wp-config.php`:

```php
define('WPS3F_UPDATE_SOURCE_URL', 'https://github.com/your-org/wp-s3-files/');
define('WPS3F_UPDATE_BRANCH', 'main'); // optional, defaults to main
define('WPS3F_UPDATE_TOKEN', 'ghp_xxx'); // optional for private repos
```

Optional filters:

- `wps3f_update_source_url`
- `wps3f_update_branch`
- `wps3f_update_token`
- `wps3f_update_slug`

## Notes

- This version uses single-request PUT uploads only (no multipart).
- Files larger than `max_offload_size_mb` remain local and are marked failed for retry/manual handling.
- Private-bucket signed delivery URLs are out of scope for V1.

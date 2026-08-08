# Disable Auto-Update Success Emails

Suppresses "update succeeded" emails for WordPress core, plugins, and themes while preserving failure and critical notices.

WordPress sends an email every time an automatic update completes successfully. On a single site that's mild noise; across a portfolio of managed sites it's a steady stream of messages that say nothing actionable, which trains you to ignore the inbox folder where the *important* update emails also land. This plugin silences the routine confirmations and leaves the alarms intact.

---

## What it silences vs. what it preserves

| Email type | Behavior |
|---|---|
| Core update succeeded | **Suppressed** |
| Core update failed | Sent |
| Core update critical error | Sent |
| Core update requires manual intervention | Sent |
| Plugin auto-updates — all succeeded | **Suppressed** |
| Plugin auto-updates — one or more failed | Sent (full batch report) |
| Theme auto-updates — all succeeded | **Suppressed** |
| Theme auto-updates — one or more failed | Sent (full batch report) |
| Automatic update debug email | **Suppressed** |
| "A new version is available" (update not auto-applied) | **Suppressed** — see [Configuration](#configuration) |

Plugin and theme emails are evaluated per batch, not per item. If WordPress updates six plugins and one fails, the email is sent and includes results for all six — you don't lose the context of what else ran.

---

## Requirements

- WordPress 5.5 or later (auto-update filters for plugins and themes were introduced in 5.5)
- PHP 7.0 or later

---

## Installation

**From the release ZIP**

1. Download the ZIP from the [Releases](../../releases) page.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP, click **Install Now**, then **Activate**.

**Manual**

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate it under **Plugins**.

**As a must-use plugin**

Drop the PHP file directly into `wp-content/mu-plugins/`. It will load automatically and cannot be deactivated from the admin — useful on sites where you don't want a client turning it off by accident. Note that mu-plugins don't appear in the normal plugin list and won't receive update notices.

There are no settings. The plugin takes effect as soon as it's active.

---

## Configuration

One behavior is worth a deliberate decision. Near the bottom of the plugin file:

```php
add_filter( 'send_core_update_notification_email', '__return_false' );
```

This is **enabled by default** in this version. It suppresses the "a new version of WordPress is available" email for core updates that were *not* applied automatically — typically major-version releases on sites configured for minor-only auto-updates.

Keep it enabled if you track available updates through your own monitoring or a management dashboard and don't need WordPress emailing you about them.

Comment it out if this site is the only place you'd find out that a major release is waiting:

```php
// add_filter( 'send_core_update_notification_email', '__return_false' );
```

---

## Notes and caveats

**You lose positive confirmation.** Once success emails are off, silence means "everything worked" *and* "the update process never ran." Those are very different states and this plugin makes them look identical from the inbox. If uptime matters, pair it with something that checks versions independently — a monitoring endpoint, a management dashboard, or a periodic manual review.

**Failure emails still depend on WordPress being able to send mail.** If `wp_mail()` is broken on the host, a failed update produces no email either way. Worth verifying mail delivery separately rather than assuming quiet means healthy.

**Update URI is set to `false`.** WordPress will not check wordpress.org for updates to this plugin, which prevents it from being silently replaced by an unrelated plugin that happens to share the same slug. Updates are done by uploading a newer ZIP.

**Filters are set at priority 10 and return early.** If another plugin also filters these hooks, the result depends on load order and priority. Unlikely to come up, but it's the first thing to check if the behavior seems inconsistent.

---

## Uninstalling

Deactivate and delete through the Plugins screen. The plugin stores no options, creates no tables, and schedules no cron events — removing it restores default email behavior immediately with nothing left behind.

---

## Changelog

### 2.0
- Batch-aware failure detection for plugin and theme updates: emails are sent when any item in a batch fails, rather than suppressing the entire batch report.
- Core update emails filtered by type, preserving `fail`, `critical`, and `manual` notices.
- Added `Update URI: false` to prevent slug collisions with the wordpress.org directory.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright (C) 2026 Advanced Micro Technologies, LLC

---

## Author

**Advanced Micro Technologies, LLC**
[proadvanced.com](https://proadvanced.com)

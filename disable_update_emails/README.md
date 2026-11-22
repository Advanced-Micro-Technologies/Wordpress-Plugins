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
| Plugin auto-updates — all succeeded | **Suppressed** |
| Plugin auto-updates — one or more failed | Sent (full batch report) |
| Theme auto-updates — all succeeded | **Suppressed** |
| Theme auto-updates — one or more failed | Sent (full batch report) |
| Automatic update debug email | **Suppressed** |
| "A new version is available" / manual update needed (update not auto-applied) | **Suppressed** — see [Configuration](#configuration) |

Plugin and theme emails are evaluated per batch, not per item. If WordPress updates six plugins and one fails, the email is sent and includes results for all six — you don't lose the context of what else ran.

---

## Requirements

- WordPress 5.5.1 or later (the plugin and theme auto-update email filters gained the update results parameter in 5.5.1)
- PHP 7.0 or later

---

## Installation

**From the release ZIP**

1. Download [`disable_update_emails.zip`](disable_update_emails.zip) from this folder.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP, click **Install Now**, then **Activate**.

**Manual**

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate it under **Plugins**.

**As a must-use plugin**

Drop the PHP file directly into `wp-content/mu-plugins/`. It will load automatically and cannot be deactivated from the admin — useful on sites where you don't want a client turning it off by accident. Note that mu-plugins don't appear in the normal plugin list and won't receive update notices. If the plugin ends up installed both ways, it detects the second copy and skips loading it rather than causing a fatal error (both copies must be version 2.1 or later; an older 2.0 copy alongside it will still conflict).

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

**Other plugins are respected.** The core, plugin, and theme filters run at priority 10 and only ever turn an email *off*: if another plugin has already suppressed an email, this plugin won't turn it back on. A plugin filtering the same hooks at a later priority can still override the result, so that's the first thing to check if behavior seems inconsistent.

---

## Uninstalling

Deactivate and delete through the Plugins screen. The plugin stores no options, creates no tables, and schedules no cron events — removing it restores default email behavior immediately with nothing left behind.

---

## Changelog

### 2.1
- Respect earlier filter decisions: emails suppressed by another plugin are no longer re-enabled.
- Guard against a fatal error when the plugin is loaded twice (e.g. as both a must-use and a regular plugin).
- Minimum WordPress version raised to 5.5.1, where the plugin/theme email filters receive update results.
- Documentation fixes: the core "manual" email is the same notice as "a new version is available" and is controlled by `send_core_update_notification_email`; corrected stale code comments and the license link.

### 2.0
- Batch-aware failure detection for plugin and theme updates: emails are sent when any item in a batch fails, rather than suppressing the entire batch report.
- Core update emails filtered by type, preserving `fail`, `critical`, and `manual` notices.
- Added `Update URI: false` to prevent slug collisions with the wordpress.org directory.

---

## License

GPL-2.0-or-later. See [LICENSE](https://github.com/Advanced-Micro-Technologies/Wordpress-Plugins/tree/main?tab=GPL-2.0-1-ov-file).

Copyright (C) 2026 Advanced Micro Technologies, LLC

---

## Author

**Advanced Micro Technologies, LLC**
[proadvanced.com](https://proadvanced.com)

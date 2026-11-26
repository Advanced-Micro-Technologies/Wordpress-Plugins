# Quiet Update Emails

Silences the routine "update succeeded" emails WordPress sends after automatic updates, keeps the failure alerts, and logs every core, plugin and theme update.

Version 3.0.1. Successor to [Disable Auto-Update Success Emails](https://github.com/Advanced-Micro-Technologies/Wordpress-Plugins/tree/eda95e306c6c733d480010a2ba6df418b8911fd8/disable_update_emails) 2.1, which is no longer kept in this repository.

**Available in the WordPress.org plugin directory: [wordpress.org/plugins/quiet-update-emails](https://wordpress.org/plugins/quiet-update-emails/).** See [Installation](#installation).

WordPress emails the site administrator each time an automatic update finishes. Across a portfolio of managed sites that becomes a stream of messages with nothing to act on, which trains you to ignore the folder where the important update emails also land. This plugin removes the routine confirmations and leaves the alarms alone. It needs no configuration: a fresh install behaves like version 2.1 of the old plugin, with one deliberate change (see the [changelog](#changelog)).

---

## What is silenced and what is sent

These are the defaults, with no settings saved.

| Email | Default |
|---|---|
| Core auto-update succeeded | **Suppressed** |
| Core auto-update failed | Sent |
| Core auto-update critical failure | Sent |
| Core "WordPress x.y is available, please update" (update not applied automatically) | Sent (changed from 2.1) |
| Plugin auto-updates, all succeeded | **Suppressed** |
| Plugin auto-updates, one or more failed | Sent, with the full batch report |
| Theme auto-updates, all succeeded | **Suppressed** |
| Theme auto-updates, one or more failed | Sent, with the full batch report |
| Automatic update debug email | **Suppressed** |

Three rules sit behind that table:

- **The plugin only ever turns an email off.** "Sent" means "WordPress's own decision is left alone". If WordPress or another plugin has already decided not to send an email, this plugin never turns it back on.
- **Plugin and theme emails are judged per batch, not per item.** If WordPress updates six plugins and one fails, the email is sent and lists all six.
- **"Sent" is not "always sent".** WordPress has its own conditions. The "available" email is only sent when the update was not installed automatically *and* WordPress.org has flagged that release for notification, and only once per version. The debug email is only sent on development builds of WordPress.

Out of scope: recovery-mode emails and every non-update email (new users, passwords, comments) are not touched.

---

## Requirements

- WordPress 5.5.1 or later
- PHP 7.0 or later

Tested up to WordPress 7.1.

---

## Installation

### From WordPress.org (recommended)

1. In WordPress, go to **Plugins → Add Plugin** (called **Add New** on older versions) and search for "Quiet Update Emails".
2. Click **Install Now**, then **Activate**. On multisite, network-activate it.

Or with WP-CLI (on multisite use `--activate-network` in place of `--activate`):

```bash
wp plugin install quiet-update-emails --activate
```

Updates arrive through the normal WordPress update screen, or by themselves if you switch on auto-updates for the plugin. The plugin's page is [wordpress.org/plugins/quiet-update-emails](https://wordpress.org/plugins/quiet-update-emails/).

### From the ZIP

The ZIP in this folder is the same release as the one on WordPress.org. Use it where a site cannot reach WordPress.org, or to install a build before it is published.

1. Download [`quiet-update-emails.zip`](quiet-update-emails.zip) from this folder.
2. In WordPress, go to **Plugins → Add Plugin → Upload Plugin**, choose the ZIP, click **Install Now**, then **Activate**. On multisite, network-activate it.

Or with WP-CLI:

```bash
wp plugin install /path/to/quiet-update-emails.zip --activate
```

A copy installed from the ZIP is recognised by WordPress.org, so it is offered later versions through the normal update screen like any other plugin.

### As a must-use plugin

A must-use plugin cannot be deactivated from the admin, which is useful where a client should not be able to switch it off. WordPress only loads PHP files that sit directly in `wp-content/mu-plugins/`, not folders, so a plugin with several files needs a small loader.

1. Unzip the plugin so that the folder is `wp-content/mu-plugins/quiet-update-emails/`.
2. Create `wp-content/mu-plugins/quiet-update-emails-loader.php` with this content:

```php
<?php
/**
 * Plugin Name: Quiet Update Emails (must-use loader)
 * Description: Loads Quiet Update Emails from the mu-plugins folder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Optional: fix settings for this site here. See "Configuration without the settings screen".
// define( 'PROADVANCED_UPDATE_EMAILS', array( 'recipients' => 'ops@example.com' ) );

require_once __DIR__ . '/quiet-update-emails/quiet-update-emails.php';
```

Things to know about a must-use install:

- It does not appear in the normal plugin list and does not receive updates. Replace the folder to update it.
- It has no activation, deactivation or uninstall step. The daily log clean-up event is therefore scheduled on first load, and removing the plugin later leaves its data behind. To clean up by hand:

```bash
wp option delete proadvanced_update_emails_settings
```

```bash
wp option delete proadvanced_update_emails_log
```

```bash
wp cron event delete proadvanced_update_emails_prune_log
```

- If the plugin is present both as a must-use and as a normal plugin, the second copy detects the first and does nothing, so there is no fatal error.

---

## Settings

**Settings → Update Emails** on a single site (requires `manage_options`). On multisite the screen is under **Network Admin → Settings → Update Emails** (requires `manage_network_options`), and both the settings and the log are network-wide.

The Settings tab is laid out as cards in two columns: Emails on the left; Delivery, Update log and Test email on the right. On narrower screens the cards stack in one column.

Nothing is stored in the database until you save the screen. Until then the defaults in code apply.

| Card | Setting on screen | Key | Default | What it does |
|---|---|---|---|---|
| Emails | WordPress core: Update succeeded | `core_success` | off | Ticked: leave the email to WordPress. Unticked: suppress it. |
| Emails | WordPress core: Update failed | `core_fail` | on | Same. |
| Emails | WordPress core: Critical failure | `core_critical` | on | Same. |
| Emails | WordPress core: New version available, not installed automatically | `core_available` | on | Same. |
| Emails | Plugins: All automatic updates in a batch succeeded | `plugin_success` | off | Same. |
| Emails | Plugins: One or more automatic updates in a batch failed | `plugin_fail` | on | Same. |
| Emails | Themes: All automatic updates in a batch succeeded | `theme_success` | off | Same. |
| Emails | Themes: One or more automatic updates in a batch failed | `theme_fail` | on | Same. |
| Emails | Debug email | `debug_email` | `suppress` | `suppress`, or `follow` to leave WordPress's decision alone. There is deliberately no value that forces it on. |
| Delivery | Send to | `recipients` | empty | One or more addresses, comma-separated. When set, every update email the plugin lets through (core, plugin, theme and debug) goes there instead of the site admin address. Invalid addresses are rejected. Empty means WordPress's default recipient. |
| Delivery | Subject tags: Add a tag to the start of the subject line | `subject_tags` | off | Prepends a tag to the subject line so inbox rules are easy. |
| Delivery | Subject tags: Failures | `tag_failed` | `[UPDATE FAILED]` | Used for core "failed" and "critical" emails, plugin/theme emails with a failure, and a debug email that reports failures. |
| Delivery | Subject tags: Update available | `tag_available` | `[UPDATE AVAILABLE]` | Used for the core "available" email. |
| Delivery | Subject tags: Successes | `tag_ok` | `[UPDATE OK]` | Used for success emails, if you have switched any on. |
| Update log | Record updates | `log_enabled` | on | Switches the update log on or off. |

**Test email.** The button in the Test email card sends a short message through `wp_mail()` to the address update emails currently go to, and shows either a confirmation or the actual mail error. A confirmation means WordPress handed the message to the mail system, not that it was delivered, so check the inbox.

**Site Health.** *Tools → Site Health* gets an entry listing which update emails are suppressed and who receives the rest. The same facts appear under *Site Health → Info → Quiet Update Emails*.

---

## Configuration without the settings screen

Precedence, highest first: **constant → filter → saved option → defaults**.

### WP-CLI

All settings are one array option, `proadvanced_update_emails_settings`, so the standard option commands work. Keys you leave out keep their defaults, and values are validated when written.

Set several values at once:

```bash
wp option update proadvanced_update_emails_settings '{"recipients":"ops@example.com","subject_tags":true}' --format=json
```

Change one value in an option that already exists:

```bash
wp option patch update proadvanced_update_emails_settings recipients "ops@example.com, backup@example.com"
```

Add one key to an option that already exists:

```bash
wp option patch insert proadvanced_update_emails_settings core_available 0
```

Go back to the defaults:

```bash
wp option delete proadvanced_update_emails_settings
```

On multisite, use `wp site option` in place of `wp option`.

### `wp-config.php` constant

Define `PROADVANCED_UPDATE_EMAILS` as an array in `wp-config.php` (above the "stop editing" line) or in a must-use file. Each key you include overrides that one setting; the rest stay editable. A field fixed this way is shown read-only on the settings screen with a note saying where it is set.

```php
define(
	'PROADVANCED_UPDATE_EMAILS',
	array(
		'recipients'     => 'ops@example.com',
		'subject_tags'   => true,
		'core_available' => false,
	)
);
```

### Filter

```php
add_filter(
	'proadvanced_update_emails_settings',
	function ( $settings ) {
		$settings['recipients'] = 'ops@example.com';
		return $settings;
	}
);
```

The filter receives the saved settings merged over the defaults. Unknown keys and invalid values are ignored. The constant is applied afterwards and wins.

---

## Update log

**Settings → Update Emails → Update log** shows a paginated table of every core, plugin and theme update, automatic or manual (dashboard, WP-CLI or an uploaded ZIP that replaces an installed plugin or theme): date and time, what was updated, old and new version, result (succeeded, failed, or failed and rolled back) and whether it was automatic or manual. **Clear log** empties it.

- **Retention is 90 days.** Older entries are deleted by a daily scheduled event, and also every time an entry is written and every time the log is viewed, so retention holds even where WP-Cron is unreliable.
- The log is stored in one option, `proadvanced_update_emails_log`, which is not autoloaded. There are no custom database tables. As a safety net the log never holds more than 500 entries; the oldest are dropped first.
- One update produces one entry. Automatic updates also fire WordPress's general upgrader hooks; the plugin takes the result from the automatic updater and discards what it saw on the general hooks.

- **Simultaneous updates are safe.** Adding an entry means reading the log, appending and writing it back, so each write takes a short lock (WordPress's own upgrader lock) and re-reads the log from the database first. Two updates that finish at the same moment, including on different sites of a multisite network, both get logged. If the lock cannot be had within 3 seconds the entry is written anyway, and a lock left by a request that died is ignored after 30 seconds.

### Limits

The log records what WordPress tells plugins. Where WordPress does not say, the entry says so instead of guessing.

- **A manual core update that fails** is logged as failed without a reason or target version. WordPress prints the reason on the update screen but does not pass it to plugins. If it fails before the download starts, nothing is logged.
- **A manual update that stops during download or unpacking** is logged as failed with the note "did not finish". The specific error is only recorded when the update ran from the Plugins or Themes screen.
- **Rollbacks are recorded for automatic updates only**: a plugin update that caused a fatal error and was restored, and a core update that was rolled back. Whether WordPress restored the previous version after a failed *manual* plugin or theme update is not detected; those are logged as failed.
- **Some failed automatic updates carry no reason**, because WordPress reports them to plugins as a bare failure (a failed download is one example). The update email, if sent, has the details.
- **Items skipped in a bulk update before they start** (already up to date, or the new version needs a newer WordPress or PHP) are not logged.
- **If the PHP process dies** in the middle of an update, the entry may be missing.
- Translation updates are not logged.

---

## Moving a site from Disable Auto-Update Success Emails

1. Install and activate Quiet Update Emails. Both plugins can be active at the same time without errors.
2. Deactivate and delete "Disable Auto-Update Success Emails". If it was installed as a must-use plugin, delete `disable_update_emails.php` from `wp-content/mu-plugins/`.

While the old plugin is still loaded it keeps suppressing the "available" and debug emails regardless of your settings here, because it turns them off unconditionally. Quiet Update Emails shows a reminder on the Plugins screen and on its own settings screen (to users who can manage plugins) until the old plugin is gone. It never deactivates or changes the old plugin itself.

The one behaviour change to be aware of: the old plugin suppressed the "a new version of WordPress is available" email; this plugin sends it by default. To keep the old behaviour, untick **New version available, not installed automatically** under WordPress core, or set `'core_available' => false` in the constant.

---

## Notes and caveats

**You lose positive confirmation.** With success emails off, silence can mean "everything worked" or "updates never ran". Check the update log or Site Health now and then, or pair the plugin with independent monitoring.

**Failure emails still depend on WordPress being able to send mail.** If `wp_mail()` is broken on the host, a failed update produces no email either way. Use the test email button.

**Other plugins are respected.** The filters run at priority 10 and only ever turn an email off. A plugin filtering the same hooks at a later priority can still override the result, so check that first if behaviour seems inconsistent.

**Privacy.** The plugin makes no external requests and does no tracking.

---

## Uninstalling

Deactivating removes the daily clean-up event. Deleting the plugin from the Plugins screen also deletes both options (`proadvanced_update_emails_settings` and `proadvanced_update_emails_log`; network options on multisite). Default email behaviour returns immediately. For a must-use install, see the manual clean-up commands above.

---

## Development

- `quiet-update-emails.php`: plugin headers, double-load guard, bootstrap
- `includes/class-settings.php`: defaults, constant/filter/option precedence, validation
- `includes/class-email-filters.php`: the email filters
- `includes/class-update-tracker.php` and `includes/class-update-log.php`: the update log
- `includes/class-admin-page.php`: settings screen, log screen, notices
- `includes/class-site-health.php`: Site Health entry and debug information
- `uninstall.php`: clean-up on deletion
- `readme.txt`: the wordpress.org directory readme

Everything global is prefixed `proadvanced_update_emails_` / `PROADVANCED_UPDATE_EMAILS`, and the classes live in the `ProAdvanced\UpdateEmails` namespace. The release ZIP contains the plugin files only (no `README.md`, no ZIP).

---

## Changelog

### 3.0.1

- Fixed: when two updates were logged at the same moment, one of the two log entries could be lost. Log writes now take a short lock and re-read the log from the database first. Tested with twelve processes writing at once, on a single site and across three sites of a multisite network, with no entries lost. Emails were never affected.
- The update log's 500-entry limit is now stated on the WordPress.org page as well as here.
- Uninstalling also removes a leftover lock, if there is one.
- Thanks to PeepSo for raising the question that led to this fix.

### 3.0.0

- New name: Quiet Update Emails, the successor to Disable Auto-Update Success Emails 2.1. New folder, file name and text domain: `quiet-update-emails`.
- **Changed default: the core "WordPress x.y is available, please update" email is now sent.** Version 2.1 suppressed it. Switch "Core update available" off to keep the old behaviour.
- New: settings screen with a switch for every update email, and "Suppress" / "Follow WordPress" for the debug email.
- New: recipient override for all update emails.
- New: optional, editable subject tags.
- New: test email button that reports the actual mail error.
- New: update log of core, plugin and theme updates, automatic and manual, with 90-day retention.
- New: Site Health entry and debug information.
- New: multisite support with network-wide settings and log.
- New: configuration by `PROADVANCED_UPDATE_EMAILS` constant, `proadvanced_update_emails_settings` filter, or WP-CLI.
- New: a notice when the old plugin is still active.
- Removed: the `Update URI: false` header, so the plugin can be hosted in the wordpress.org directory.

---

## License

GPLv2 or later. See [LICENSE](../LICENSE).

Copyright (C) 2026 Advanced Micro Technologies, LLC

---

## Author

**Advanced Micro Technologies, LLC**
[proadvanced.com](https://proadvanced.com)

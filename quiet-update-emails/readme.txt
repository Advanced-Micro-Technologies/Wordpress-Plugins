=== Quiet Update Emails ===
Contributors: webshaun
Tags: auto-update, email, notifications, updates, update log
Requires at least: 5.5.1
Tested up to: 7.1
Stable tag: 3.0.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Silences routine automatic update emails, keeps the failure alerts, and logs every core, plugin and theme update.

== Description ==

WordPress emails the site administrator every time an automatic update finishes. On one site that is mild noise. Across many sites it is a stream of messages that say nothing you need to act on, and it buries the ones that matter.

Quiet Update Emails turns off the routine "everything worked" emails and leaves the alarms alone. It works as soon as it is activated; you do not have to configure anything.

**What happens by default**

* Core auto-update succeeded: suppressed
* Core auto-update failed: sent
* Core auto-update critical failure: sent
* Core update available but not installed automatically: sent
* Plugin auto-updates, all succeeded: suppressed
* Plugin auto-updates, one or more failed: sent, with the full report for the batch
* Theme auto-updates, all succeeded: suppressed
* Theme auto-updates, one or more failed: sent, with the full report for the batch
* Automatic update debug email: suppressed

"Sent" means the plugin leaves WordPress's own decision alone. The plugin only ever turns an email off. If WordPress or another plugin has already decided not to send an email, it stays off.

**Features**

* A switch for each of the emails above.
* Recipient override: send update emails to one or more addresses of your choice instead of the site admin address.
* Optional subject tags such as `[UPDATE FAILED]`, `[UPDATE AVAILABLE]` and `[UPDATE OK]`, so inbox rules are easy to write. The tags can be edited.
* A test email button that reports the real mail error if sending fails.
* An update log of every core, plugin and theme update, automatic or manual, with old and new version and the result. Entries are deleted after 90 days.
* A Site Health entry, and a section in Site Health Info, showing which emails are suppressed and who receives the rest.
* Multisite support: settings and the log are network-wide, under Network Admin > Settings.
* Configuration without the admin screen, through a `wp-config.php` constant, a filter, or WP-CLI.

**Privacy**

The plugin makes no external requests, loads nothing from other servers and does not track anything. Its settings and the update log are stored in two options in your own database.

**Development**

Source code, issues and full documentation: [github.com/Advanced-Micro-Technologies/Wordpress-Plugins](https://github.com/Advanced-Micro-Technologies/Wordpress-Plugins/tree/main/quiet-update-emails)

== Installation ==

1. In WordPress, go to Plugins > Add New, search for "Quiet Update Emails", then install and activate it. On multisite, network-activate it.
2. That is all. The defaults listed in the description apply immediately.
3. To change anything, go to Settings > Update Emails (on multisite: Network Admin > Settings > Update Emails).

**Configuring many sites without the settings screen**

All settings live in one array option, `proadvanced_update_emails_settings`. Any key you leave out keeps its default. With WP-CLI:

`wp option update proadvanced_update_emails_settings '{"recipients":"ops@example.com","subject_tags":true}' --format=json`

On multisite use `wp site option` instead of `wp option`.

You can also fix settings in `wp-config.php`. A value set this way wins over the settings screen, which then shows the field as read-only:

`define( 'PROADVANCED_UPDATE_EMAILS', array( 'recipients' => 'ops@example.com', 'subject_tags' => true ) );`

The setting keys are: `core_success`, `core_fail`, `core_critical`, `core_available`, `plugin_success`, `plugin_fail`, `theme_success`, `theme_fail` (true sends, false suppresses), `debug_email` (`suppress` or `follow`), `recipients`, `subject_tags`, `tag_failed`, `tag_available`, `tag_ok` and `log_enabled`.

== Frequently Asked Questions ==

= Will I still hear about failed updates? =

Yes. Failure and critical failure emails are sent by default. For plugins and themes, one failure in a batch sends the report for the whole batch, so you also see what did succeed.

= Can the plugin make WordPress send an email it would not send on its own? =

No. It can only suppress. Switching an email on means "leave it to WordPress".

= I switched on the "update available" email but never receive it. Why? =

WordPress sends that email only when an update was not installed automatically and WordPress.org has flagged the release for notification, and only once per version. This plugin does not change those rules.

= Why can the debug email not be forced on? =

WordPress sends it only on development builds, where it is useful. The two choices are "Suppress" and "Follow WordPress".

= Does a recipient override change the site admin address? =

No. It only changes where update emails go. Other WordPress emails are not affected.

= What does the update log not catch? =

It records what WordPress tells plugins. A manual core update that fails is logged without a reason, because WordPress shows the reason on screen only. A manual update that stops during download is logged as "did not finish". Whether WordPress restored the old version after a failed manual plugin or theme update is not detected; rollbacks are recorded for automatic updates.

= Does silence now mean everything is fine? =

Not on its own. No email can mean "updates worked" or "updates never ran". Check the update log or Site Health now and then, and use the test email button to make sure the site can send mail at all.

= I used "Disable Auto-Update Success Emails". How do I switch? =

Activate Quiet Update Emails, then deactivate and delete the old plugin. While both are active the old one still suppresses the "update available" and debug emails, and this plugin shows a reminder on the Plugins screen.

= What is removed when I delete the plugin? =

Both options (settings and log) and the daily clean-up event.

== Changelog ==

= 3.0.0 =
* New name. Successor to "Disable Auto-Update Success Emails" 2.1.
* Changed: the "a new WordPress version is available" email is now sent by default. Version 2.1 suppressed it.
* New: settings screen with a switch for every update email.
* New: recipient override, optional subject tags, and a test email button.
* New: update log for core, plugin and theme updates with 90-day retention.
* New: Site Health entry and debug information.
* New: multisite support with network-wide settings.
* New: configuration by constant, filter or WP-CLI.

== Upgrade Notice ==

= 3.0.0 =
First release under this name. If you used Disable Auto-Update Success Emails, note that the "update available" email is now sent by default.

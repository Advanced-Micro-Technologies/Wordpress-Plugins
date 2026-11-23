<?php
/**
 * Plugin Name:       Quiet Update Emails
 * Plugin URI:        https://github.com/Advanced-Micro-Technologies/Wordpress-Plugins/tree/main/quiet-update-emails
 * Description:       Silences routine automatic update emails, keeps the failure alerts, and logs every core, plugin and theme update.
 * Version:           3.0.0
 * Requires at least: 5.5.1
 * Requires PHP:      7.0
 * Author:            Advanced Micro Technologies, LLC
 * Author URI:        https://proadvanced.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quiet-update-emails
 * Network:           true
 *
 * Copyright (C) 2026 Advanced Micro Technologies, LLC
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package QuietUpdateEmails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Bail if a copy is already loaded, for example when the plugin is installed
 * both as a must-use plugin and as a regular plugin. This check runs before
 * any class file is loaded, and this file declares no functions or classes of
 * its own, so a second copy cannot cause a "cannot redeclare" fatal error.
 */
if ( defined( 'PROADVANCED_UPDATE_EMAILS_VERSION' ) ) {
	return;
}

define( 'PROADVANCED_UPDATE_EMAILS_VERSION', '3.0.0' );
define( 'PROADVANCED_UPDATE_EMAILS_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-email-filters.php';
require_once __DIR__ . '/includes/class-update-log.php';
require_once __DIR__ . '/includes/class-update-tracker.php';
require_once __DIR__ . '/includes/class-site-health.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-plugin.php';

\ProAdvanced\UpdateEmails\Plugin::instance()->register();

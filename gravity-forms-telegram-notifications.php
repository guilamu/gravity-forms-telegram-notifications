<?php
/**
 * Plugin Name: Gravity Forms Telegram Notifications
 * Plugin URI: https://github.com/guilamu/gravity-forms-telegram-notifications
 * Description: Sends a Telegram message to a chat, group, channel or forum topic when a Gravity Form is submitted.
 * Version: 1.0.0
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Update URI: https://github.com/guilamu/gravity-forms-telegram-notifications/
 * Text Domain: gravity-forms-telegram-notifications
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 *
 * This plugin is not affiliated with, endorsed by, or sponsored by Rocketgenius, Inc.
 * (Gravity Forms) or Telegram FZ-LLC.
 *
 * ------------------------------------------------------------------------
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU Affero General Public License as published by the Free Software Foundation, either version 3
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 *
 * @package Gravity_Forms_Telegram_Notifications
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GF_TELEGRAM_VERSION', '1.0.0' );
define( 'GF_TELEGRAM_PLUGIN_FILE', __FILE__ );
define( 'GF_TELEGRAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_TELEGRAM_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_TELEGRAM_BASENAME', plugin_basename( __FILE__ ) );

// GitHub auto-updates. Loaded outside the Gravity Forms bootstrap so updates keep working even
// when Gravity Forms is inactive.
require_once GF_TELEGRAM_PATH . 'includes/class-github-updater.php';

// If Gravity Forms is loaded, bootstrap the add-on.
add_action( 'gform_loaded', array( 'GF_Telegram_Bootstrap', 'load' ), 5 );

// Translations must be loaded on init or later; GF does not load them for third party add-ons.
add_action( 'init', 'gf_telegram_load_textdomain' );

// Bug reports, when the Guilamu Bug Reporter plugin is available.
add_action( 'plugins_loaded', 'gf_telegram_register_bug_reporter', 20 );

// Links shown under the plugin name on the Plugins screen.
add_filter( 'plugin_row_meta', 'gf_telegram_plugin_row_meta', 10, 2 );

/**
 * Class GF_Telegram_Bootstrap
 *
 * Handles the loading of the add-on and registers it with the Add-On Framework.
 *
 * @since 1.0
 */
class GF_Telegram_Bootstrap {

	/**
	 * If the Feed Add-On Framework exists, the add-on is loaded.
	 *
	 * @since 1.0
	 */
	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		// The API class is loaded here rather than on demand: the settings pages read its limits
		// and default URL while rendering, before any request is ever made.
		require_once GF_TELEGRAM_PATH . 'includes/class-gf-telegram-api.php';
		require_once GF_TELEGRAM_PATH . 'includes/class-gf-telegram-formatter.php';
		require_once GF_TELEGRAM_PATH . 'includes/class-gf-telegram-chats.php';
		require_once GF_TELEGRAM_PATH . 'class-gf-telegram.php';

		GFAddOn::register( 'GFTelegram' );
	}
}

/**
 * Loads the plugin translations.
 *
 * @since 1.0
 */
function gf_telegram_load_textdomain() {
	load_plugin_textdomain(
		'gravity-forms-telegram-notifications',
		false,
		dirname( GF_TELEGRAM_BASENAME ) . '/languages'
	);
}

/**
 * Registers the plugin with the Guilamu Bug Reporter, when it is installed.
 *
 * @since 1.0
 */
function gf_telegram_register_bug_reporter() {

	if ( ! class_exists( 'Guilamu_Bug_Reporter' ) ) {
		return;
	}

	Guilamu_Bug_Reporter::register(
		array(
			'slug'        => 'gravity-forms-telegram-notifications',
			'name'        => 'Gravity Forms Telegram Notifications',
			'version'     => GF_TELEGRAM_VERSION,
			'github_repo' => 'guilamu/gravity-forms-telegram-notifications',
		)
	);
}

/**
 * Adds the View details and Report a Bug links to the plugin's row on the Plugins screen.
 *
 * @since 1.0
 *
 * @param array  $links The current row meta links.
 * @param string $file  The plugin file the row belongs to.
 *
 * @return array
 */
function gf_telegram_plugin_row_meta( $links, $file ) {

	if ( GF_TELEGRAM_BASENAME !== $file ) {
		return $links;
	}

	// "View details" thickbox link — same pattern as WordPress.org-hosted plugins.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url(
			self_admin_url(
				'plugin-install.php?tab=plugin-information&plugin=gravity-forms-telegram-notifications'
				. '&TB_iframe=true&width=772&height=926'
			)
		),
		esc_attr__( 'More information about Gravity Forms Telegram Notifications', 'gravity-forms-telegram-notifications' ),
		esc_attr__( 'Gravity Forms Telegram Notifications', 'gravity-forms-telegram-notifications' ),
		esc_html__( 'View details', 'gravity-forms-telegram-notifications' )
	);

	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$links[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="gravity-forms-telegram-notifications" data-plugin-name="%s">%s</a>',
			esc_attr__( 'Gravity Forms Telegram Notifications', 'gravity-forms-telegram-notifications' ),
			esc_html__( '🐛 Report a Bug', 'gravity-forms-telegram-notifications' )
		);
	} else {
		$links[] = sprintf(
			'<a href="https://github.com/guilamu/guilamu-bug-reporter/releases" target="_blank">%s</a>',
			esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'gravity-forms-telegram-notifications' )
		);
	}

	return $links;
}

/**
 * Returns an instance of the GFTelegram class.
 *
 * @since 1.0
 *
 * @see GFTelegram::get_instance()
 *
 * @return GFTelegram
 */
function gf_telegram() {
	return GFTelegram::get_instance();
}

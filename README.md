# Gravity Forms Telegram Notifications

[![Latest Release](https://img.shields.io/github/v/release/guilamu/gravity-forms-telegram-notifications?color=blue)](https://github.com/guilamu/gravity-forms-telegram-notifications/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE) [![WordPress: 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-blue.svg)](https://wordpress.org) [![PHP: 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

Sends a Telegram message to a chat, group, channel or forum topic when a Gravity Form is submitted.

## Messaging

- Write the message with Gravity Forms merge tags; `{all_fields}` expands the whole submission as plain text
- Choose HTML, MarkdownV2 or plain text formatting, with submitted values escaped automatically for the mode you pick
- Split messages beyond Telegram's 4096 character limit across several sends, cut on line boundaries with open formatting tags closed and reopened
- Add inline buttons written as `Label | URL`, with merge tags in both halves
- Attach any file upload field, sent as documents or as inline photos
- Send silently, hide link previews, or prevent forwarding and saving

## Routing

- Send to a private chat, a group, a channel, or one topic inside a forum group
- Discover chat IDs from the settings page instead of hunting for them by hand
- Pick a fixed recipient per feed, fall back to site defaults, or resolve the recipient from the submission through merge tags
- Send to several recipients from one feed, with per-recipient results recorded on the entry
- Restrict a feed with standard Gravity Forms conditional logic
- Delay the notification until payment is received on payment forms

## Key Features

- **Multilingual:** works with content in any language, and counts message length the way Telegram does so emoji never break the limit
- **Translation-Ready:** all strings are internationalized
- **Secure:** nonce and capability checks on every AJAX endpoint, submitted values escaped per parse mode, bot token never written to the log and definable as a constant outside the database
- **GitHub Updates:** automatic updates from GitHub releases
- **No dependencies:** no Composer, no bundled SDK — the Telegram Bot API is called directly over `wp_remote_post`
- **Background sending:** notifications are processed asynchronously, so the HTTP call never delays a form submission

## Requirements

- A Telegram bot token from [@BotFather](https://t.me/botfather) — send `/newbot` and follow the prompts
- Gravity Forms 2.5 or higher
- WordPress 5.9 or higher
- PHP 7.4 or higher

## Installation

1. Upload the `gravity-forms-telegram-notifications` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Forms → Settings → Telegram** and paste your bot token, then save
4. Message your bot or add it to your group, click **Find my chats**, then add a recipient to **Default Recipients**
5. Create a feed on any form under **Form Settings → Telegram**

## FAQ

### Why does "Find my chats" say a webhook is registered?
Telegram delivers updates either by webhook or by polling, never both, so a bot with a webhook cannot be polled for its recent chats. Another plugin using the same bot is the usual cause — the error names the webhook URL so you can identify it. Either give this site its own bot, or type the chat ID in by hand.

### Why is my group missing from the chat list?
Telegram has no endpoint listing a bot's chats: a bot only learns about a chat once it receives a message from it. Post in the group, then search again.

### Nothing arrives. Where do I look?
Enable logging in **Forms → Settings → Logging** and read the `gravityformstelegram` log. Every send, skip and failure is recorded, and failures are also written to the entry as a note. The bot token is never logged.

### Why was one of my buttons ignored?
Telegram rejects an entire message when a single button URL is malformed, so invalid lines are dropped and logged instead. Links must be absolute and start with `http`, `https` or `tg`.

### Can I keep the bot token out of the database?
Yes, define it in `wp-config.php` and the settings field disappears:
```php
define( 'GF_TELEGRAM_BOT_TOKEN', '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ' );
```

### Can I change the message before it is sent?
Yes, use the `gform_telegram_message_args` filter, also available per form and per feed:
```php
add_filter( 'gform_telegram_message_args', function( $args, $feed, $entry, $form ) {
    $args['disable_notification'] = true;
    return $args;
}, 10, 4 );
```

### Can I route requests through a proxy?
Yes, for sites where Telegram is blocked, use the `gform_telegram_api_base_url` filter:
```php
add_filter( 'gform_telegram_api_base_url', function( $url ) {
    return 'https://telegram-proxy.example.org';
} );
```

## Project Structure

```
.
├── gravity-forms-telegram-notifications.php      # Main plugin file
├── class-gf-telegram.php                         # Feed add-on: settings, feeds, sending, AJAX
├── README.md
├── assets
│   ├── admin.css                                 # Settings page tools styling
│   └── admin.js                                  # Chat discovery and test message buttons
├── includes
│   ├── Parsedown.php                             # Markdown parser for the details popup
│   ├── class-gf-telegram-api.php                 # Telegram Bot API client
│   ├── class-gf-telegram-chats.php               # Discovered chat storage
│   ├── class-gf-telegram-formatter.php           # Escaping, splitting, button URLs
│   └── class-github-updater.php                  # GitHub auto-updates
├── languages
│   ├── gravity-forms-telegram-notifications-fr_FR.mo  # French translation (binary)
│   ├── gravity-forms-telegram-notifications-fr_FR.po  # French translation (source)
│   └── gravity-forms-telegram-notifications.pot       # Translation template
└── tests
    ├── README.md                                 # How to run the suites
    ├── bootstrap.php                             # WordPress and Gravity Forms test doubles
    ├── run.php                                   # Runs every suite
    ├── stub-server.php                           # Fake Telegram Bot API
    ├── test-api.php                              # API client
    ├── test-bootstrap.php                        # Class loading and load order
    ├── test-chats.php                            # Chat discovery and AJAX
    ├── test-feed.php                             # Feed processing
    └── test-formatter.php                        # Escaping and splitting
```

## Changelog

### 1.0.0 - 2026-07-26
- Initial release
- **New:** Gravity Forms feed add-on with conditional logic, merge tags, entry notes, logging and background processing
- **New:** Sends to private chats, groups, channels and forum topics, with recipients fixed, taken from the site defaults, or resolved from the submission
- **New:** HTML and MarkdownV2 formatting with submitted values escaped for the chosen mode
- **New:** Messages over 4096 characters split on line boundaries, with open formatting tags closed and reopened across the split
- **New:** Inline buttons written as `Label | URL`; lines that cannot produce a valid button are skipped and logged rather than failing the message
- **New:** File upload fields attached as documents or as inline photos
- **New:** Chat discovery and test message from the settings page, reporting a conflicting webhook by name
- **New:** Bot token definable as `GF_TELEGRAM_BOT_TOKEN`, never written to the log
- **New:** Requests routable through a self-hosted Bot API server or proxy

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/gravity-forms-telegram-notifications/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/gravity-forms-telegram-notifications).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE) file for details.

---

Made with love for the WordPress community

# Tests

Plain PHP CLI. No PHPUnit, no Composer, no WordPress install — `bootstrap.php` stands up test
doubles for the handful of WordPress and Gravity Forms functions the plugin touches, so the suites
run against the real plugin source in a second.

```bash
php tests/run.php
```

`run.php` starts `stub-server.php` (a fake Telegram Bot API), runs every suite, and shuts the
server down again. To run one suite on its own, start the stub yourself first:

```bash
php -S 127.0.0.1:8799 tests/stub-server.php
```

Set `TG_STUB_PORT` if 8799 is taken.

## Suites

| File | Covers |
|---|---|
| `test-bootstrap.php` | The main plugin file: every class the plugin references is loaded, in the right order, from a path with the right capitalization |
| `test-api.php` | `GF_Telegram_API`: response envelopes, error mapping, rate limit retry, token masking, JSON request encoding |
| `test-feed.php` | `GFTelegram`: recipient resolution, feed processing, entry notes, partial failure, filters |
| `test-formatter.php` | `GF_Telegram_Formatter`: escaping per parse mode, tag allowlist, message splitting |
| `test-chats.php` | `GF_Telegram_Chats` and the settings page AJAX: discovery, the stored list, the webhook conflict, capability and nonce checks |

## What these do and do not prove

They exercise **this plugin's logic**. They deliberately do not reimplement WordPress or Gravity
Forms, so anything delegated to those is out of scope here:

- `GFCommon::replace_variables()` is a simplified double — it substitutes `{key}` from the entry
  and expands `{all_fields}`. Real merge tag behavior (modifiers, field types, pricing fields) is
  Gravity Forms' concern.
- `wp_kses()` is approximated at the tag level with `strip_tags()`. Real attribute filtering is
  WordPress's concern and is not asserted here.
- Nothing in these suites touches the Gravity Forms admin UI. Settings rendering, the feed list,
  the menu icon and capability enforcement need a real install — see the Playground workflow.

Each suite requires the class files it needs directly, which is why `test-bootstrap.php` exists: a
class the suites load by hand but the plugin never loads would pass everywhere here and be fatal on
the settings page.

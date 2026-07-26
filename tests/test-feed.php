<?php
/**
 * Exercises GFTelegram's feed logic with the Gravity Forms framework stubbed out.
 * Verifies this plugin's own branching, not Gravity Forms itself.
 */

require __DIR__ . '/bootstrap.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-api.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-formatter.php';
require GF_TELEGRAM_PLUGIN_DIR . '/class-gf-telegram.php';

// --- Test doubles ------------------------------------------------------------------------------

class Fake_API {
	public $sent            = array();
	public $uploads         = array();
	public $fail_for        = array();
	public $next_message_id = 1000;
	public function send_message( $args ) {
		$this->sent[] = $args;
		$chat         = rgar( $args, 'chat_id' );
		if ( isset( $this->fail_for[ $chat ] ) ) {
			return new WP_Error( 400, $this->fail_for[ $chat ] );
		}
		return array( 'message_id' => $this->next_message_id++, 'chat' => array( 'id' => $chat ) );
	}
	public function send_document( $args, $path ) {
		return $this->record_upload( 'document', $args, $path );
	}
	public function send_photo( $args, $path ) {
		return $this->record_upload( 'photo', $args, $path );
	}
	protected function record_upload( $kind, $args, $path ) {
		$this->uploads[] = array( 'kind' => $kind, 'args' => $args, 'path' => $path );
		return array( 'message_id' => $this->next_message_id++ );
	}
}

/**
 * Creates a file inside the fake uploads directory and returns its public URL.
 */
function make_upload( $name, $bytes = 32 ) {
	$uploads = wp_upload_dir();
	if ( ! is_dir( $uploads['basedir'] ) ) {
		mkdir( $uploads['basedir'], 0777, true );
	}
	file_put_contents( $uploads['basedir'] . '/' . $name, str_repeat( 'x', $bytes ) );
	return $uploads['baseurl'] . '/' . $name;
}

class Testable_GFTelegram extends GFTelegram {
	public $fake_api = null;
	public function initialize_api() {
		if ( $this->fake_api ) {
			$this->api = $this->fake_api;
			return true;
		}
		$this->api = false;
		return false;
	}
}

function make_addon( $defaults = '', $with_api = true ) {
	$addon                  = new Testable_GFTelegram();
	$addon->plugin_settings = array( 'defaultChatIds' => $defaults );
	$addon->fake_api        = $with_api ? new Fake_API() : null;
	return $addon;
}

$form  = array( 'id' => 7, 'title' => 'Contact' );
$entry = array( 'id' => 55, '1' => 'Amelie', '2' => 'amelie@example.org', 'chat' => '-100999' );

echo "\n1. parse_chat_ids normalizes whatever the admin typed\n";
$addon = make_addon();
check( 'splits on newlines', array( '111', '222' ) === $addon->parse_chat_ids( "111\n222" ) );
check( 'splits on commas', array( '111', '222' ) === $addon->parse_chat_ids( '111, 222' ) );
check( 'handles CRLF', array( '111', '222' ) === $addon->parse_chat_ids( "111\r\n222" ) );
check( 'trims and drops blanks', array( '111', '222' ) === $addon->parse_chat_ids( "  111  \n\n  222\n" ) );
check( 'deduplicates', array( '111' ) === $addon->parse_chat_ids( "111\n111" ) );
check( 'keeps @usernames', array( '@newsroom' ) === $addon->parse_chat_ids( '@newsroom' ) );
check( 'blank input gives empty array', array() === $addon->parse_chat_ids( '' ) );

echo "\n2. get_chat_ids picks the right recipients\n";
$addon = make_addon( "-100111\n-100222" );
check( 'empty setting falls back to defaults', array( '-100111', '-100222' ) === $addon->get_chat_ids( array( 'meta' => array( 'chatId' => '' ) ), $entry, $form ) );
check( 'explicit chat overrides defaults', array( '-100333' ) === $addon->get_chat_ids( array( 'meta' => array( 'chatId' => '-100333' ) ), $entry, $form ) );
$custom_feed = array( 'meta' => array( 'chatId' => 'gf_custom', 'chatId_custom' => '{chat}' ) );
check( 'custom value resolves merge tags', array( '-100999' ) === $addon->get_chat_ids( $custom_feed, $entry, $form ) );

echo "\n3. get_message_text renders merge tags as text\n";
$addon = make_addon();
$feed  = array( 'meta' => array( 'message' => "New entry from {1}\n{all_fields}" ) );
$text  = $addon->get_message_text( $feed, $entry, $form );
check( 'merge tag replaced', false !== strpos( $text, 'New entry from Amelie' ), $text );
check( 'all_fields expanded', false !== strpos( $text, 'Field 2: amelie@example.org' ), $text );
check( 'text format requested, not html', 'text' === $GLOBALS['last_replace_format'], $GLOBALS['last_replace_format'] );

echo "\n4. Misconfiguration fails loudly and sends nothing\n";
$addon  = make_addon( '', false );
$result = $addon->process_feed( array( 'id' => 1, 'meta' => array() ), $entry, $form );
check( 'invalid token is an error', is_wp_error( $result ) && 'api_not_initialized' === $result->get_error_code() );
check( 'feed error recorded', 1 === count( $addon->feed_errors ) );

$addon  = make_addon( '' );
$result = $addon->process_feed( array( 'id' => 1, 'meta' => array( 'message' => 'hi' ) ), $entry, $form );
check( 'no recipient is an error', is_wp_error( $result ) && 'no_chat_id' === $result->get_error_code() );
check( 'nothing was sent', 0 === count( $addon->fake_api->sent ) );

// A message made only of merge tags for fields the user left blank.
$blank_entry = array( 'id' => 55, '1' => '' );
$addon       = make_addon( '-100111' );
$result      = $addon->process_feed( array( 'id' => 1, 'meta' => array( 'message' => '{1}' ) ), $blank_entry, $form );
check( 'message that resolves to nothing is an error', is_wp_error( $result ) && 'empty_message' === $result->get_error_code(), is_wp_error( $result ) ? $result->get_error_code() : 'no error' );
check( 'nothing was sent', 0 === count( $addon->fake_api->sent ) );

echo "\n5. Successful send\n";
$addon = make_addon( '-100111' );
$feed  = array(
	'id'   => 3,
	'meta' => array(
		'message'               => 'New entry from {1}',
		'parseMode'             => '',
		'disableNotification'   => '1',
		'disableWebPagePreview' => '1',
		'protectContent'        => '',
	),
);
$result = $addon->process_feed( $feed, $entry, $form );
$sent   = $addon->fake_api->sent[0];
check( 'entry is returned on success', $result === $entry );
check( 'one message sent', 1 === count( $addon->fake_api->sent ) );
check( 'chat_id set per recipient', '-100111' === rgar( $sent, 'chat_id' ) );
check( 'text carries the merge tag value', 'New entry from Amelie' === rgar( $sent, 'text' ) );
check( 'checked option becomes boolean true', true === rgar( $sent, 'disable_notification' ) );
check( 'unchecked option becomes boolean false', false === rgar( $sent, 'protect_content' ) );
check( 'absent topic id is null', null === rgar( $sent, 'message_thread_id' ) );
check( 'success note added', 1 === count( $addon->notes ) && 'success' === $addon->notes[0][2] );
check( 'note names chat and message id', false !== strpos( $addon->notes[0][1], '-100111 (#1000)' ), $addon->notes[0][1] );
check( 'no feed errors', 0 === count( $addon->feed_errors ) );

echo "\n6. Topic ID is passed through as an integer when set\n";
$addon = make_addon( '-100111' );
$feed  = array( 'id' => 3, 'meta' => array( 'message' => 'x', 'messageThreadId' => '42' ) );
$addon->process_feed( $feed, $entry, $form );
check( 'thread id is an int', 42 === rgar( $addon->fake_api->sent[0], 'message_thread_id' ) );

echo "\n7. Several recipients each get their own message\n";
$addon = make_addon( "-100111\n-100222\n@newsroom" );
$feed  = array( 'id' => 3, 'meta' => array( 'message' => 'x' ) );
$addon->process_feed( $feed, $entry, $form );
check( 'three messages sent', 3 === count( $addon->fake_api->sent ) );
check( 'each has its own chat_id', '@newsroom' === rgar( $addon->fake_api->sent[2], 'chat_id' ) );
check( 'single success note', 1 === count( $addon->notes ) );

echo "\n8. Partial failure is reported without losing what did send\n";
$addon                     = make_addon( "-100111\n-100222" );
$addon->fake_api->fail_for = array( '-100222' => 'Bad Request: chat not found' );
$result                    = $addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x' ) ), $entry, $form );
check( 'feed is marked failed', is_wp_error( $result ) && 'send_failed' === $result->get_error_code() );
check( 'both recipients attempted', 2 === count( $addon->fake_api->sent ) );
check( 'error names the failed chat and reason', false !== strpos( $addon->feed_errors[0], '-100222 (Bad Request: chat not found)' ), $addon->feed_errors[0] );
check( 'error also names what did deliver', false !== strpos( $addon->feed_errors[0], '-100111 (#1000)' ), $addon->feed_errors[0] );
check( 'no duplicate success note', 0 === count( $addon->notes ) );

echo "\n9. gform_telegram_message_args can rewrite the payload\n";
add_test_filter( 'gform_telegram_message_args', function ( $args, $feed, $entry, $form ) {
	$args['text']            = 'rewritten';
	$args['protect_content'] = true;
	return $args;
} );
$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'original' ) ), $entry, $form );
check( 'filtered text is sent', 'rewritten' === rgar( $addon->fake_api->sent[0], 'text' ) );
check( 'filtered option is sent', true === rgar( $addon->fake_api->sent[0], 'protect_content' ) );
check( 'chat_id is still applied after filtering', '-100111' === rgar( $addon->fake_api->sent[0], 'chat_id' ) );
reset_test_filters();

echo "\n10. Parse mode reaches Telegram\n";
$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'parseMode' => 'HTML' ) ), $entry, $form );
check( 'parse_mode sent when set', 'HTML' === rgar( $addon->fake_api->sent[0], 'parse_mode' ) );

$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'parseMode' => '' ) ), $entry, $form );
check( 'parse_mode omitted when None', null === rgar( $addon->fake_api->sent[0], 'parse_mode' ) );

// Feeds saved before the setting existed have no parseMode key at all. They must keep behaving
// like the UI default rather than silently switching to unparsed text.
$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x' ) ), $entry, $form );
check( 'absent parse mode falls back to the default', 'HTML' === rgar( $addon->fake_api->sent[0], 'parse_mode' ) );

echo "\n11. A long message is split across several sends\n";
$addon = make_addon( '-100111' );
$lines = array();
for ( $i = 0; $i < 400; $i++ ) {
	$lines[] = "Line {$i}: " . str_repeat( 'x', 20 );
}
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => implode( "\n", $lines ), 'parseMode' => '' ) ), $entry, $form );
check( 'more than one message sent', count( $addon->fake_api->sent ) > 1, count( $addon->fake_api->sent ) . ' sent' );
$all_within_limit = true;
$reassembled      = '';
foreach ( $addon->fake_api->sent as $message ) {
	if ( mb_strlen( rgar( $message, 'text' ) ) > GF_Telegram_API::MAX_MESSAGE_LENGTH ) {
		$all_within_limit = false;
	}
	$reassembled .= rgar( $message, 'text' );
}
check( 'every chunk fits the limit', $all_within_limit );
check( 'no content was dropped', false !== strpos( $reassembled, 'Line 399' ) && false !== strpos( $reassembled, 'Line 0:' ) );
check( 'note records every message id', false !== strpos( $addon->notes[0][1], '#1000' ) && false !== strpos( $addon->notes[0][1], '#1001' ), $addon->notes[0][1] );

echo "\n12. Buttons are parsed from the Label | URL syntax\n";
$addon    = make_addon( '-100111' );
$feed     = array( 'meta' => array( 'buttons' => "View entry | https://example.org/e/55\nSite | https://example.org" ) );
$keyboard = $addon->get_inline_keyboard( $feed, $entry, $form );
check( 'keyboard built', is_array( $keyboard ) && isset( $keyboard['inline_keyboard'] ) );
check( 'one row per button', 2 === count( $keyboard['inline_keyboard'] ) );
check( 'label parsed', 'View entry' === $keyboard['inline_keyboard'][0][0]['text'] );
check( 'url parsed', 'https://example.org/e/55' === $keyboard['inline_keyboard'][0][0]['url'] );

echo "\n13. Merge tags work in both halves of a button\n";
$feed     = array( 'meta' => array( 'buttons' => 'Reply to {1} | https://example.org/u?mail={2}' ) );
$keyboard = $addon->get_inline_keyboard( $feed, $entry, $form );
check( 'label merge tag resolved', 'Reply to Amelie' === $keyboard['inline_keyboard'][0][0]['text'], $keyboard['inline_keyboard'][0][0]['text'] );
check( 'url merge tag resolved', 'https://example.org/u?mail=amelie@example.org' === $keyboard['inline_keyboard'][0][0]['url'], $keyboard['inline_keyboard'][0][0]['url'] );

echo "\n14. A URL containing the separator is not cut in half\n";
$feed     = array( 'meta' => array( 'buttons' => 'Report | https://example.org/r?filter=a|b' ) );
$keyboard = $addon->get_inline_keyboard( $feed, $entry, $form );
check( 'only the first separator splits the line', 'https://example.org/r?filter=a|b' === $keyboard['inline_keyboard'][0][0]['url'], $keyboard['inline_keyboard'][0][0]['url'] );

echo "\n15. Unusable button lines are dropped, not sent\n";
$addon    = make_addon( '-100111' );
$feed     = array(
	'meta' => array(
		'buttons' => "Good | https://example.org\n"
			. "No separator here\n"
			. "  | https://example.org\n"
			. "Bad scheme | javascript:alert(1)\n"
			. "Relative | /entries/5\n"
			. "\n"
			. 'Also good | https://example.org/2',
	),
);
$keyboard = $addon->get_inline_keyboard( $feed, $entry, $form );
check( 'only the valid buttons survive', 2 === count( $keyboard['inline_keyboard'] ), count( $keyboard['inline_keyboard'] ) . ' rows' );
check( 'first survivor is correct', 'Good' === $keyboard['inline_keyboard'][0][0]['text'] );
check( 'second survivor is correct', 'Also good' === $keyboard['inline_keyboard'][1][0]['text'] );
$dropped = 0;
foreach ( $addon->logs as $log ) {
	if ( false !== strpos( $log, 'Skipping button' ) ) {
		$dropped++;
	}
}
check( 'every dropped line was logged', 4 === $dropped, $dropped . ' logged' );

echo "\n16. No usable buttons means no keyboard at all\n";
check( 'empty setting gives null', null === $addon->get_inline_keyboard( array( 'meta' => array() ), $entry, $form ) );
check( 'all-invalid gives null', null === $addon->get_inline_keyboard( array( 'meta' => array( 'buttons' => 'Bad | nope' ) ), $entry, $form ) );

echo "\n17. The keyboard reaches Telegram on the message it belongs to\n";
$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'buttons' => 'Open | https://example.org' ) ), $entry, $form );
$markup = rgar( $addon->fake_api->sent[0], 'reply_markup' );
check( 'single message carries the keyboard', is_array( $markup ) && 'Open' === $markup['inline_keyboard'][0][0]['text'] );

$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'parseMode' => '' ) ), $entry, $form );
check( 'no buttons configured means no reply_markup', null === rgar( $addon->fake_api->sent[0], 'reply_markup' ) );

echo "\n18. On a split message only the last part carries the keyboard\n";
$addon = make_addon( '-100111' );
$lines = array();
for ( $i = 0; $i < 400; $i++ ) {
	$lines[] = "Line {$i}: " . str_repeat( 'x', 20 );
}
$addon->process_feed(
	array(
		'id'   => 3,
		'meta' => array( 'message' => implode( "\n", $lines ), 'parseMode' => '', 'buttons' => 'Open | https://example.org' ),
	),
	$entry,
	$form
);
$sent  = $addon->fake_api->sent;
$count = count( $sent );
check( 'message was split', $count > 1, $count . ' sent' );
check( 'first part has no keyboard', null === rgar( $sent[0], 'reply_markup' ) );
check( 'last part has the keyboard', is_array( rgar( $sent[ $count - 1 ], 'reply_markup' ) ) );
$with_keyboard = 0;
foreach ( $sent as $message ) {
	if ( is_array( rgar( $message, 'reply_markup' ) ) ) {
		$with_keyboard++;
	}
}
check( 'the keyboard appears exactly once', 1 === $with_keyboard, $with_keyboard . ' messages' );

echo "\n19. A filter can replace the keyboard\n";
add_test_filter( 'gform_telegram_message_args', function ( $args, $feed, $entry, $form ) {
	$args['reply_markup'] = array( 'inline_keyboard' => array( array( array( 'text' => 'Filtered', 'url' => 'https://example.org/f' ) ) ) );
	return $args;
} );
$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x' ) ), $entry, $form );
$markup = rgar( $addon->fake_api->sent[0], 'reply_markup' );
check( 'filtered keyboard is sent', is_array( $markup ) && 'Filtered' === $markup['inline_keyboard'][0][0]['text'] );
reset_test_filters();

echo "\n20. Attachments are resolved from the selected fields only\n";
$doc_url   = make_upload( 'report.pdf' );
$image_url = make_upload( 'photo.png' );
$file_form = array(
	'id'     => 7,
	'fields' => array(
		new GF_Field_Double( 4, 'fileupload', 'Report' ),
		new GF_Field_Double( 5, 'fileupload', 'Photo' ),
	),
);
$file_entry = array( 'id' => 55, '4' => $doc_url, '5' => $image_url );

$addon = make_addon( '-100111' );
$feed  = array( 'meta' => array( 'attachField_4' => '1' ) );
$paths = $addon->get_attachment_files( $feed, $file_entry, $file_form );
check( 'only the selected field is attached', 1 === count( $paths ), count( $paths ) . ' files' );
check( 'path resolves to the file on disk', 'report.pdf' === basename( $paths[0] ) && is_readable( $paths[0] ) );

$feed  = array( 'meta' => array( 'attachField_4' => '1', 'attachField_5' => '1' ) );
$paths = $addon->get_attachment_files( $feed, $file_entry, $file_form );
check( 'both fields attach when both are selected', 2 === count( $paths ) );

$paths = $addon->get_attachment_files( array( 'meta' => array() ), $file_entry, $file_form );
check( 'nothing selected means nothing attached', 0 === count( $paths ) );

echo "\n21. A multi-file field yields every upload\n";
$a     = make_upload( 'a.txt' );
$b     = make_upload( 'b.txt' );
$multi = array( 'id' => 55, '4' => wp_json_encode( array( $a, $b ) ) );
$paths = $addon->get_attachment_files( array( 'meta' => array( 'attachField_4' => '1' ) ), $multi, $file_form );
check( 'both files resolved', 2 === count( $paths ), count( $paths ) . ' files' );
check( 'single value still works', 1 === count( $addon->get_attachment_files( array( 'meta' => array( 'attachField_4' => '1' ) ), $file_entry, $file_form ) ) );
check( 'empty field yields nothing', 0 === count( $addon->get_attachment_files( array( 'meta' => array( 'attachField_4' => '1' ) ), array( 'id' => 1, '4' => '' ), $file_form ) ) );

echo "\n22. A URL with no file behind it is skipped and logged\n";
$addon   = make_addon( '-100111' );
$missing = array( 'id' => 55, '4' => 'https://example.org/wp-content/uploads/gone.pdf' );
$paths   = $addon->get_attachment_files( array( 'meta' => array( 'attachField_4' => '1' ) ), $missing, $file_form );
check( 'nothing resolved', 0 === count( $paths ) );
$logged = false;
foreach ( $addon->logs as $log ) {
	if ( false !== strpos( $log, 'no readable file behind' ) ) {
		$logged = true;
	}
}
check( 'the miss was logged', $logged );

echo "\n23. Files are sent after the message, once per recipient\n";
$addon = make_addon( "-100111\n-100222" );
$feed  = array(
	'id'   => 3,
	'meta' => array( 'message' => 'New entry', 'parseMode' => '', 'attachField_4' => '1' ),
);
$result = $addon->process_feed( $feed, $file_entry, $file_form );
check( 'feed succeeded', $result === $file_entry );
check( 'text went to both chats', 2 === count( $addon->fake_api->sent ) );
check( 'file went to both chats', 2 === count( $addon->fake_api->uploads ) );
check( 'sent as a document by default', 'document' === $addon->fake_api->uploads[0]['kind'] );
check( 'each upload names its chat', '-100222' === rgar( $addon->fake_api->uploads[1]['args'], 'chat_id' ) );
check( 'note lists the file message ids', false !== strpos( $addon->notes[0][1], '#1001' ), $addon->notes[0][1] );

echo "\n24. Uploads inherit the delivery options but carry no text\n";
$addon = make_addon( '-100111' );
$feed  = array(
	'id'   => 3,
	'meta' => array(
		'message'             => 'x',
		'parseMode'           => 'HTML',
		'attachField_4'       => '1',
		'disableNotification' => '1',
		'protectContent'      => '1',
		'messageThreadId'     => '9',
		'buttons'             => 'Open | https://example.org',
	),
);
$addon->process_feed( $feed, $file_entry, $file_form );
$upload_args = $addon->fake_api->uploads[0]['args'];
check( 'silent flag inherited', true === rgar( $upload_args, 'disable_notification' ) );
check( 'protect flag inherited', true === rgar( $upload_args, 'protect_content' ) );
check( 'topic inherited', 9 === rgar( $upload_args, 'message_thread_id' ) );
check( 'no text on the upload', null === rgar( $upload_args, 'text' ) );
check( 'no parse mode on the upload', null === rgar( $upload_args, 'parse_mode' ) );
check( 'no keyboard on the upload', null === rgar( $upload_args, 'reply_markup' ) );

echo "\n25. Images go inline only when asked for\n";
$image_entry = array( 'id' => 55, '5' => $image_url );
$image_form  = array( 'id' => 7, 'fields' => array( new GF_Field_Double( 5, 'fileupload', 'Photo' ) ) );

$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'attachField_5' => '1' ) ), $image_entry, $image_form );
check( 'image sent as a document by default', 'document' === $addon->fake_api->uploads[0]['kind'] );

$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'attachField_5' => '1', 'sendImagesAsPhotos' => '1' ) ), $image_entry, $image_form );
check( 'image sent as a photo when enabled', 'photo' === $addon->fake_api->uploads[0]['kind'] );

$addon = make_addon( '-100111' );
$addon->process_feed( array( 'id' => 3, 'meta' => array( 'message' => 'x', 'attachField_4' => '1', 'sendImagesAsPhotos' => '1' ) ), $file_entry, $file_form );
check( 'a pdf is never sent as a photo', 'document' === $addon->fake_api->uploads[0]['kind'] );

echo "\n26. Oversized files fail the feed instead of failing silently\n";
$addon = make_addon( '-100111' );
$feed  = array( 'id' => 3, 'meta' => array( 'message' => 'x', 'attachField_4' => '1' ) );

// Stand in for a file over the API limit without writing 50 MB to disk.
class Oversized_GFTelegram extends Testable_GFTelegram {
	public function send_attachment( $chat_id, $path, $args, $feed ) {
		return new WP_Error( 'file_too_large', basename( $path ) . ' is larger than the 50 MB Telegram allows' );
	}
}
$addon                  = new Oversized_GFTelegram();
$addon->plugin_settings = array( 'defaultChatIds' => '-100111' );
$addon->fake_api        = new Fake_API();
$result                 = $addon->process_feed( $feed, $file_entry, $file_form );
check( 'feed is marked failed', is_wp_error( $result ) && 'send_failed' === $result->get_error_code() );
check( 'the text still went out', 1 === count( $addon->fake_api->sent ) );
check( 'the entry says which file was too large', false !== strpos( $addon->feed_errors[0], 'report.pdf is larger' ), $addon->feed_errors[0] );

echo "\n27. Size limits pick the sending method\n";
$addon = make_addon( '-100111' );
$addon->initialize_api(); // send_attachment() is called directly here, outside process_feed().
check( 'photo limit is below the document limit', GF_Telegram_API::MAX_PHOTO_BYTES < GF_Telegram_API::MAX_DOCUMENT_BYTES );
$small_image = make_upload( 'small.jpg', 64 );
$feed        = array( 'meta' => array( 'sendImagesAsPhotos' => '1' ) );
$path        = $addon->get_physical_file_path( $small_image );
$result      = $addon->send_attachment( '-100111', $path, array(), $feed );
check( 'a small jpg goes as a photo', 'photo' === $addon->fake_api->uploads[0]['kind'] );
check( 'upload returned a message id', 1000 === rgar( $result, 'message_id' ) );

// Clean up the fake uploads directory.
foreach ( (array) glob( wp_upload_dir()['basedir'] . '/*' ) as $file ) {
	@unlink( $file );
}

summarize();

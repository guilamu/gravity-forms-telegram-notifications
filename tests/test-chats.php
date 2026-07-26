<?php
/**
 * Exercises chat discovery: reading chats out of getUpdates, the stored list, and the AJAX
 * handlers behind the settings page buttons.
 */

require __DIR__ . '/bootstrap.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-api.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-formatter.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-chats.php';
require GF_TELEGRAM_PLUGIN_DIR . '/class-gf-telegram.php';

// --- Test doubles ------------------------------------------------------------------------------

class Chats_Fake_API {
	public $updates      = array();
	public $webhook      = array( 'url' => '' );
	public $sent         = array();
	public $fail_send    = array();
	public function get_updates() {
		return $this->updates;
	}
	public function get_webhook_info() {
		return $this->webhook;
	}
	public function send_message( $args ) {
		$this->sent[] = $args;
		$chat         = rgar( $args, 'chat_id' );
		if ( isset( $this->fail_send[ $chat ] ) ) {
			return new WP_Error( 400, $this->fail_send[ $chat ] );
		}
		return array( 'message_id' => 1 );
	}
}

class Chats_GFTelegram extends GFTelegram {
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

function make_chats_addon( $defaults = '', $with_api = true ) {
	$addon                  = new Chats_GFTelegram();
	$addon->plugin_settings = array( 'defaultChatIds' => $defaults );
	$addon->fake_api        = $with_api ? new Chats_Fake_API() : null;
	return $addon;
}

/**
 * Runs an AJAX handler and returns the response it would have sent.
 */
function capture_json( $callable ) {
	try {
		call_user_func( $callable );
	} catch ( Test_Json_Response $response ) {
		return $response;
	}
	return null;
}

/**
 * Returns one key from every row of a list.
 */
function pluck( $rows, $key ) {
	$values = array();
	foreach ( (array) $rows as $row ) {
		$values[] = isset( $row[ $key ] ) ? $row[ $key ] : null;
	}
	return $values;
}

function reset_state() {
	$GLOBALS['test_options']     = array();
	$GLOBALS['test_user_can']    = true;
	$GLOBALS['test_nonce_valid'] = true;
}

reset_state();

// A realistic getUpdates payload: a private chat, a group, a channel post and a duplicate.
$updates = array(
	array( 'update_id' => 1, 'message' => array( 'chat' => array( 'id' => 12345, 'type' => 'private', 'first_name' => 'Amelie', 'last_name' => 'Roux', 'username' => 'amelie' ) ) ),
	array( 'update_id' => 2, 'message' => array( 'chat' => array( 'id' => -100222, 'type' => 'supergroup', 'title' => 'Site alerts' ) ) ),
	array( 'update_id' => 3, 'channel_post' => array( 'chat' => array( 'id' => -100333, 'type' => 'channel', 'title' => 'Announcements' ) ) ),
	array( 'update_id' => 4, 'message' => array( 'chat' => array( 'id' => -100222, 'type' => 'supergroup', 'title' => 'Site alerts' ) ) ),
	array( 'update_id' => 5, 'my_chat_member' => array( 'chat' => array( 'id' => -100444, 'type' => 'group', 'title' => 'Newsroom' ) ) ),
	array( 'update_id' => 6, 'poll' => array( 'id' => 'x' ) ),
);

echo "\n1. Chats are pulled out of an updates payload\n";
$found = GF_Telegram_Chats::extract_from_updates( $updates );
check( 'every distinct chat found', 4 === count( $found ), count( $found ) . ' chats' );
check( 'keyed by chat id', isset( $found['-100222'] ) );
check( 'duplicates collapse', 1 === count( array_filter( $found, function ( $c ) { return '-100222' === $c['id']; } ) ) );
check( 'group title kept', 'Site alerts' === $found['-100222']['title'] );
check( 'channel post seen', 'Announcements' === $found['-100333']['title'] );
check( 'my_chat_member seen', 'Newsroom' === $found['-100444']['title'] );
check( 'private chat named after the person', 'Amelie Roux' === $found['12345']['title'] );
check( 'chat type recorded', 'supergroup' === $found['-100222']['type'] );
check( 'an update with no chat is ignored', ! isset( $found[''] ) );

echo "\n2. Odd payloads do not break discovery\n";
check( 'empty updates give no chats', array() === GF_Telegram_Chats::extract_from_updates( array() ) );
$nameless = GF_Telegram_Chats::extract_from_updates( array( array( 'message' => array( 'chat' => array( 'id' => 9, 'type' => 'private', 'username' => 'ghost' ) ) ) ) );
check( 'falls back to the username', '@ghost' === $nameless['9']['title'] );
$anonymous = GF_Telegram_Chats::extract_from_updates( array( array( 'message' => array( 'chat' => array( 'id' => 8, 'type' => 'private' ) ) ) ) );
check( 'a chat with no name at all still resolves', '' === $anonymous['8']['title'] && '8' === $anonymous['8']['id'] );
check( 'a chat with no id is skipped', array() === GF_Telegram_Chats::extract_from_updates( array( array( 'message' => array( 'chat' => array( 'type' => 'private' ) ) ) ) ) );

echo "\n3. Labels read the way a human would name the chat\n";
check( 'title and id shown', 'Site alerts (-100222)' === GF_Telegram_Chats::describe( $found['-100222'] ) );
check( 'nameless chat falls back to the id', '8' === GF_Telegram_Chats::describe( $anonymous['8'] ) );

echo "\n4. Known chats survive a second search\n";
reset_state();
GF_Telegram_Chats::save( $found );
check( 'stored and read back', 4 === count( GF_Telegram_Chats::get_all() ) );
$later  = GF_Telegram_Chats::extract_from_updates( array( array( 'message' => array( 'chat' => array( 'id' => -100555, 'type' => 'group', 'title' => 'Support' ) ) ) ) );
$merged = GF_Telegram_Chats::merge( GF_Telegram_Chats::get_all(), $later );
check( 'new chat added to the known ones', 5 === count( $merged ), count( $merged ) . ' chats' );
check( 'older chats not lost', isset( $merged['-100222'] ) );
$renamed = GF_Telegram_Chats::merge( $merged, array( '-100222' => array( 'id' => '-100222', 'type' => 'supergroup', 'title' => 'Site alerts renamed' ) ) );
check( 'a renamed chat updates instead of duplicating', 5 === count( $renamed ) && 'Site alerts renamed' === $renamed['-100222']['title'] );
$titles = pluck( $renamed, 'title' );
$sorted = $titles;
usort( $sorted, 'strcasecmp' );
check( 'list comes back sorted by name', $titles === $sorted, implode( ' | ', $titles ) );
GF_Telegram_Chats::clear();
check( 'clearing forgets everything', array() === GF_Telegram_Chats::get_all() );

echo "\n5. Discovery stores what it finds and reports back\n";
reset_state();
$addon                   = make_chats_addon();
$addon->fake_api->updates = $updates;
$response                = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'responded', null !== $response );
check( 'succeeded', $response->success );
check( 'chats persisted', 4 === count( GF_Telegram_Chats::get_all() ) );
check( 'count reported', false !== strpos( $response->data['message'], '4 chats' ), $response->data['message'] );
check( 'table markup returned', false !== strpos( $response->data['markup'], '-100222' ) );
check( 'names appear in the table', false !== strpos( $response->data['markup'], 'Site alerts' ) );

echo "\n6. Finding nothing says so plainly\n";
reset_state();
$addon                    = make_chats_addon();
$addon->fake_api->updates = array();
$response                 = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'still a success', $response->success );
check( 'explains what to do next', false !== strpos( $response->data['message'], 'Message your bot' ), $response->data['message'] );

echo "\n7. A webhook on the bot is explained, not just reported\n";
reset_state();
$addon                    = make_chats_addon();
$addon->fake_api->updates = new WP_Error( 409, "Conflict: can't use getUpdates method while webhook is active" );
$addon->fake_api->webhook = array( 'url' => 'https://example.org/?ultimate-integration-for-telegram=webhook' );
$response                 = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'reported as a failure', ! $response->success );
check( 'names the real cause', false !== strpos( $response->data['message'], 'webhook registered' ), $response->data['message'] );
check( 'points at the other plugin', false !== strpos( $response->data['message'], 'Another plugin' ) );
check( 'shows the registered URL', false !== strpos( $response->data['message'], 'ultimate-integration-for-telegram' ), $response->data['message'] );
check( 'offers a way forward', false !== strpos( $response->data['message'], 'by hand' ) );

echo "\n8. Other API errors surface as themselves\n";
reset_state();
$addon                    = make_chats_addon();
$addon->fake_api->updates = new WP_Error( 401, 'Unauthorized' );
$response                 = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'reported as a failure', ! $response->success );
check( 'passes the description through', 'Unauthorized' === $response->data['message'], $response->data['message'] );

echo "\n9. Discovery needs a working token\n";
reset_state();
$addon    = make_chats_addon( '', false );
$response = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'reported as a failure', ! $response->success );
check( 'asks for the token first', false !== strpos( $response->data['message'], 'valid bot token' ) );

echo "\n10. The test message goes to the saved recipients\n";
reset_state();
$addon    = make_chats_addon( "-100111\n-100222" );
$response = capture_json( array( $addon, 'ajax_send_test' ) );
check( 'succeeded', $response->success );
check( 'one message per recipient', 2 === count( $addon->fake_api->sent ) );
check( 'names the site', false !== strpos( $addon->fake_api->sent[0]['text'], 'Test Site' ), $addon->fake_api->sent[0]['text'] );
check( 'sent as HTML', 'HTML' === $addon->fake_api->sent[0]['parse_mode'] );
check( 'reports where it went', false !== strpos( $response->data['message'], '-100222' ), $response->data['message'] );

echo "\n11. A failing recipient is named\n";
reset_state();
$addon                      = make_chats_addon( "-100111\n-100222" );
$addon->fake_api->fail_send = array( '-100222' => 'Bad Request: chat not found' );
$response                   = capture_json( array( $addon, 'ajax_send_test' ) );
check( 'reported as a failure', ! $response->success );
check( 'names the chat and the reason', false !== strpos( $response->data['message'], '-100222 (Bad Request: chat not found)' ), $response->data['message'] );

echo "\n12. Testing without recipients explains itself\n";
reset_state();
$addon    = make_chats_addon( '' );
$response = capture_json( array( $addon, 'ajax_send_test' ) );
check( 'reported as a failure', ! $response->success );
check( 'asks for a recipient', false !== strpos( $response->data['message'], 'default recipient' ), $response->data['message'] );
check( 'nothing was sent', 0 === count( $addon->fake_api->sent ) );

echo "\n13. Both endpoints refuse unauthorized callers\n";
reset_state();
$GLOBALS['test_user_can'] = false;
$addon                    = make_chats_addon( '-100111' );
$addon->fake_api->updates = $updates;
$response                 = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'discovery refused', ! $response->success && false !== strpos( $response->data['message'], 'not allowed' ) );
check( 'nothing was stored', array() === GF_Telegram_Chats::get_all() );
$response = capture_json( array( $addon, 'ajax_send_test' ) );
check( 'test send refused', ! $response->success && false !== strpos( $response->data['message'], 'not allowed' ) );
check( 'nothing was sent', 0 === count( $addon->fake_api->sent ) );

reset_state();
$GLOBALS['test_nonce_valid'] = false;
$addon                       = make_chats_addon( '-100111' );
$addon->fake_api->updates    = $updates;
$response                    = capture_json( array( $addon, 'ajax_discover_chats' ) );
check( 'a bad nonce stops discovery', ! $response->success );
check( 'nothing was stored', array() === GF_Telegram_Chats::get_all() );

echo "\n14. Discovered chats reach the feed dropdown\n";
reset_state();
GF_Telegram_Chats::save( $found );
$addon   = make_chats_addon( "-100111\n-100222" );
$choices = $addon->get_chat_id_choices();
$values  = pluck( $choices, 'value' );
check( 'default option first', '' === $choices[0]['value'] );
check( 'custom option last', 'gf_custom' === $choices[ count( $choices ) - 1 ]['value'] );
check( 'a discovered chat is offered', in_array( '-100333', $values, true ), implode( ', ', $values ) );
check( 'discovered chats show their name', in_array( 'Announcements (-100333)', pluck( $choices, 'label' ), true ) );
check( 'a default not yet discovered is still offered', in_array( '-100111', $values, true ) );
check( 'a default already discovered is not repeated', 1 === count( array_keys( $values, '-100222', true ) ), implode( ', ', $values ) );

summarize();

<?php
/**
 * Exercises GF_Telegram_API against the local stub server.
 *
 * Requires stub-server.php to be listening; run.php starts it for you.
 */

require __DIR__ . '/bootstrap.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-api.php';

$base = TG_STUB_URL;

echo "\n1. Valid token -> getMe returns the result payload\n";
$api    = new GF_Telegram_API( 'good', $base );
$result = $api->get_me();
check( 'returns an array, not WP_Error', is_array( $result ), var_export( $result, true ) );
check( 'username is unwrapped from result', 'gf_test_bot' === rgar( $result, 'username' ) );

echo "\n2. Invalid token -> WP_Error carrying Telegram's own code and description\n";
$api    = new GF_Telegram_API( 'bad', $base );
$result = $api->get_me();
check( 'is WP_Error', is_wp_error( $result ) );
check( 'code is 401 (int)', 401 === $result->get_error_code(), var_export( $result->get_error_code(), true ) );
check( 'description preserved', 'Unauthorized' === $result->get_error_message(), $result->get_error_message() );

echo "\n3. Non-JSON gateway error -> invalid_response, body excerpt kept as data\n";
$api    = new GF_Telegram_API( 'html', $base );
$result = $api->get_me();
check( 'is WP_Error', is_wp_error( $result ) );
check( 'code is invalid_response', 'invalid_response' === $result->get_error_code(), var_export( $result->get_error_code(), true ) );
check( 'message names the HTTP status', false !== strpos( $result->get_error_message(), '502' ), $result->get_error_message() );

echo "\n4. 429 with a short retry_after -> one retry, then success\n";
@unlink( sys_get_temp_dir() . '/tg-stub-counter.txt' );
$GLOBALS['request_log'] = array();
$api                    = new GF_Telegram_API( 'ratelimit', $base );
$start                  = microtime( true );
$result                 = $api->get_me();
$elapsed                = microtime( true ) - $start;
check( 'succeeds after retry', is_array( $result ), var_export( $result, true ) );
check( 'exactly two requests were made', 2 === count( $GLOBALS['request_log'] ), count( $GLOBALS['request_log'] ) . ' requests' );
check( 'waited for retry_after', $elapsed >= 1.0, sprintf( '%.2fs', $elapsed ) );

echo "\n5. 429 with an unreasonable retry_after -> no retry, error returned\n";
$GLOBALS['request_log'] = array();
$api                    = new GF_Telegram_API( 'ratelimit-long', $base );
$start                  = microtime( true );
$result                 = $api->get_me();
$elapsed                = microtime( true ) - $start;
check( 'is WP_Error', is_wp_error( $result ) );
check( 'did not sleep for an hour', $elapsed < 2, sprintf( '%.2fs', $elapsed ) );
check( 'only one request was made', 1 === count( $GLOBALS['request_log'] ), count( $GLOBALS['request_log'] ) . ' requests' );
check( 'retry_after exposed as error data', 3600 === rgar( (array) $result->get_error_data(), 'retry_after' ) );

echo "\n6. Token never survives into an error message\n";
$api    = new GF_Telegram_API( 'leaky', $base );
$result = $api->get_me();
check( 'is WP_Error', is_wp_error( $result ) );
check( 'token absent from message', false === strpos( $result->get_error_message(), 'leaky' ), $result->get_error_message() );
check( 'mask placeholder present', false !== strpos( $result->get_error_message(), '[bot-token-redacted]' ), $result->get_error_message() );
check( 'mask() scrubs arbitrary text', '[bot-token-redacted] here' === $api->mask( 'leaky here' ) );

echo "\n7. Transport failure -> WP_Error, not a fatal\n";
$api    = new GF_Telegram_API( 'good', 'http://127.0.0.1:9' );
$result = $api->get_me();
check( 'is WP_Error', is_wp_error( $result ) );

echo "\n8. sendMessage -> returns the Message object\n";
$api    = new GF_Telegram_API( 'good', $base );
$result = $api->send_message( array( 'chat_id' => '-1001234567890', 'text' => 'Hello' ) );
check( 'is not WP_Error', ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'message_id returned', 4242 === rgar( $result, 'message_id' ) );

echo "\n9. Nested reply_markup survives the wire as a real structure\n";
$markup = array(
	'inline_keyboard' => array(
		array(
			array( 'text' => 'View entry', 'url' => 'https://example.org/entry/1' ),
			array( 'text' => 'Second', 'url' => 'https://example.org/2' ),
		),
	),
);
$result   = $api->send_message( array( 'chat_id' => 1, 'text' => 'x', 'reply_markup' => $markup ) );
$received = rgar( $result, 'received' );
check( 'reply_markup is still an array', is_array( rgar( $received, 'reply_markup' ) ) );
check( 'button text arrived intact', 'View entry' === $received['reply_markup']['inline_keyboard'][0][0]['text'], json_encode( rgar( $received, 'reply_markup' ) ) );
check( 'keyboard row is a JSON array, not an object', false !== strpos( $GLOBALS['last_sent_body'], '"inline_keyboard":[[' ), $GLOBALS['last_sent_body'] );

echo "\n10. Booleans stay booleans instead of becoming \"1\" and \"\"\n";
$result = $api->send_message( array( 'chat_id' => 1, 'text' => 'x', 'disable_notification' => true, 'protect_content' => false ) );
check( 'disable_notification is a boolean server side', 'boolean' === rgar( $result, 'raw_type' ), rgar( $result, 'raw_type' ) );
check( 'false is transmitted, not dropped', array_key_exists( 'protect_content', rgar( $result, 'received' ) ) );
check( 'false is still false', false === rgar( rgar( $result, 'received' ), 'protect_content' ) );

echo "\n11. Unicode and emoji round-trip unchanged\n";
$text   = "Réunion à 14h — c'est confirmé ✅🎉";
$result = $api->send_message( array( 'chat_id' => 1, 'text' => $text ) );
check( 'text arrived byte-identical', $text === rgar( rgar( $result, 'received' ), 'text' ), rgar( rgar( $result, 'received' ), 'text' ) );

echo "\n12. Null arguments are stripped from the payload\n";
$result = $api->send_message( array( 'chat_id' => 1, 'text' => 'x', 'message_thread_id' => null ) );
check( 'null key absent from request body', false === strpos( $GLOBALS['last_sent_body'], 'message_thread_id' ), $GLOBALS['last_sent_body'] );
check( 'request still succeeded', ! is_wp_error( $result ) );

echo "\n13. Malformed calls fail without spending an HTTP request\n";
$GLOBALS['request_log'] = array();
$missing_chat           = $api->send_message( array( 'text' => 'x' ) );
$missing_text           = $api->send_message( array( 'chat_id' => 1 ) );
check( 'missing chat_id is an error', is_wp_error( $missing_chat ) );
check( 'missing chat_id code', 'missing_chat_id' === $missing_chat->get_error_code() );
check( 'missing text is an error', is_wp_error( $missing_text ) );
check( 'missing text code', 'missing_text' === $missing_text->get_error_code() );
check( 'no requests were made', 0 === count( $GLOBALS['request_log'] ), count( $GLOBALS['request_log'] ) . ' requests' );

echo "\n14. Telegram rejects the chat -> error surfaces verbatim\n";
$api    = new GF_Telegram_API( 'nochat', $base );
$result = $api->send_message( array( 'chat_id' => 999, 'text' => 'x' ) );
check( 'is WP_Error', is_wp_error( $result ) );
check( 'code is 400', 400 === $result->get_error_code() );
check( 'description preserved', 'Bad Request: chat not found' === $result->get_error_message(), $result->get_error_message() );

echo "\n15. Uploads are sent as multipart and arrive intact\n";
$upload = sys_get_temp_dir() . '/gftg-upload-test.txt';
file_put_contents( $upload, "line one\nline two — with an accent and a 🎉\n" );

$api    = new GF_Telegram_API( 'good', $base );
$result = $api->send_document(
	array(
		'chat_id'              => '-100111',
		'disable_notification' => true,
		'protect_content'      => false,
		'message_thread_id'    => null,
	),
	$upload
);
check( 'is not WP_Error', ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'server parsed the multipart body', 'document' === rgar( $result, 'file_field' ), json_encode( $result ) );
check( 'file name preserved', 'gftg-upload-test.txt' === rgar( $result, 'file_name' ) );
check( 'file arrived byte-identical', base64_encode( file_get_contents( $upload ) ) === rgar( $result, 'file_contents' ) );
check( 'size matches', filesize( $upload ) === rgar( $result, 'file_size' ) );

$post = (array) rgar( $result, 'post_fields' );
check( 'chat_id travelled alongside the file', '-100111' === rgar( $post, 'chat_id' ) );
check( 'true became the string "true", not "1"', 'true' === rgar( $post, 'disable_notification' ), var_export( rgar( $post, 'disable_notification' ), true ) );
check( 'false became the string "false", not ""', 'false' === rgar( $post, 'protect_content' ), var_export( rgar( $post, 'protect_content' ), true ) );
check( 'null argument was dropped', ! array_key_exists( 'message_thread_id', $post ) );

echo "\n16. sendPhoto uses the photo parameter\n";
$image  = sys_get_temp_dir() . '/gftg-upload-test.png';
file_put_contents( $image, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$result = $api->send_photo( array( 'chat_id' => '-100111' ), $image );
check( 'photo parameter used', 'photo' === rgar( $result, 'file_field' ), json_encode( $result ) );
check( 'method was sendPhoto', 'sendPhoto' === rgar( $result, 'method' ) );
check( 'binary content survived', base64_encode( file_get_contents( $image ) ) === rgar( $result, 'file_contents' ) );

echo "\n17. A missing file fails before anything is sent\n";
$GLOBALS['request_log'] = array();
$result                 = $api->send_document( array( 'chat_id' => '-100111' ), sys_get_temp_dir() . '/gftg-does-not-exist.txt' );
check( 'is WP_Error', is_wp_error( $result ) );
check( 'code is unreadable_file', 'unreadable_file' === $result->get_error_code(), var_export( $result->get_error_code(), true ) );
check( 'no request was made', 0 === count( $GLOBALS['request_log'] ), count( $GLOBALS['request_log'] ) . ' requests' );

echo "\n18. An upload without a chat still fails locally\n";
$GLOBALS['request_log'] = array();
$result                 = $api->send_document( array(), $upload );
check( 'is WP_Error', is_wp_error( $result ) );
check( 'code is missing_chat_id', 'missing_chat_id' === $result->get_error_code() );
check( 'no request was made', 0 === count( $GLOBALS['request_log'] ) );

@unlink( $upload );
@unlink( $image );

summarize();

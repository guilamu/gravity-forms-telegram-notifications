<?php
/**
 * Telegram Bot API stub. Behavior is selected by the token in the path, so a suite asks for the
 * response it wants by choosing a token: good, bad, html, ratelimit, ratelimit-long, leaky, nochat.
 *
 * Started automatically by run.php.
 */

header( 'Content-Type: application/json' );

function rgar_stub( $array, $key ) {
	return isset( $array[ $key ] ) ? $array[ $key ] : null;
}

$uri = $_SERVER['REQUEST_URI'];

if ( ! preg_match( '#^/bot([^/]+)/(\w+)#', $uri, $m ) ) {
	http_response_code( 404 );
	echo json_encode( array( 'ok' => false, 'error_code' => 404, 'description' => 'Not Found' ) );
	return;
}

list( , $token, $method ) = $m;
$counter_file = sys_get_temp_dir() . '/tg-stub-counter.txt';

switch ( $token ) {

	case 'good':
		// Uploads arrive as multipart, which the built-in server parses into $_POST and $_FILES.
		if ( 'sendDocument' === $method || 'sendPhoto' === $method ) {
			$field = 'sendPhoto' === $method ? 'photo' : 'document';
			echo json_encode( array(
				'ok'     => true,
				'result' => array(
					'message_id'    => 777,
					'method'        => $method,
					'post_fields'   => $_POST,
					'file_field'    => isset( $_FILES[ $field ] ) ? $field : '',
					'file_name'     => isset( $_FILES[ $field ]['name'] ) ? $_FILES[ $field ]['name'] : '',
					'file_size'     => isset( $_FILES[ $field ]['size'] ) ? (int) $_FILES[ $field ]['size'] : 0,
					// Base64 encoded: an uploaded binary is not valid UTF-8, and json_encode would
					// fail on the raw bytes.
					'file_contents' => isset( $_FILES[ $field ]['tmp_name'] ) ? base64_encode( file_get_contents( $_FILES[ $field ]['tmp_name'] ) ) : '',
				),
			) );
			break;
		}
		if ( 'sendMessage' === $method ) {
			// Echo the decoded request back so the caller can assert on what actually arrived.
			$received = json_decode( file_get_contents( 'php://input' ), true );
			echo json_encode( array(
				'ok'     => true,
				'result' => array(
					'message_id' => 4242,
					'chat'       => array( 'id' => rgar_stub( $received, 'chat_id' ) ),
					'received'   => $received,
					'raw_type'   => gettype( rgar_stub( $received, 'disable_notification' ) ),
				),
			) );
			break;
		}
		echo json_encode( array(
			'ok'     => true,
			'result' => array( 'id' => 777, 'is_bot' => true, 'username' => 'gf_test_bot', 'first_name' => 'GF Test' ),
		) );
		break;

	case 'nochat':
		http_response_code( 400 );
		echo json_encode( array( 'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found' ) );
		break;

	case 'bad':
		http_response_code( 401 );
		echo json_encode( array( 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ) );
		break;

	case 'html':
		http_response_code( 502 );
		header( 'Content-Type: text/html' );
		echo '<html><body><h1>502 Bad Gateway</h1></body></html>';
		break;

	case 'ratelimit':
		$n = file_exists( $counter_file ) ? (int) file_get_contents( $counter_file ) : 0;
		file_put_contents( $counter_file, $n + 1 );
		if ( 0 === $n ) {
			http_response_code( 429 );
			echo json_encode( array(
				'ok'         => false,
				'error_code' => 429,
				'description' => 'Too Many Requests: retry after 1',
				'parameters' => array( 'retry_after' => 1 ),
			) );
		} else {
			echo json_encode( array( 'ok' => true, 'result' => array( 'username' => 'gf_test_bot', 'attempts' => $n + 1 ) ) );
		}
		break;

	case 'ratelimit-long':
		http_response_code( 429 );
		echo json_encode( array(
			'ok'          => false,
			'error_code'  => 429,
			'description' => 'Too Many Requests: retry after 3600',
			'parameters'  => array( 'retry_after' => 3600 ),
		) );
		break;

	case 'leaky':
		http_response_code( 400 );
		echo json_encode( array(
			'ok'          => false,
			'error_code'  => 400,
			'description' => "Bad Request: the token leaky was rejected at {$uri}",
		) );
		break;

	default:
		http_response_code( 404 );
		echo json_encode( array( 'ok' => false, 'error_code' => 404, 'description' => 'Unknown stub token' ) );
}

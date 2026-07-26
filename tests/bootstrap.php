<?php
/**
 * Shared test bootstrap: stands up just enough WordPress and Gravity Forms for the plugin's own
 * classes to run under plain PHP CLI. These are test doubles, not reimplementations — they exist
 * so the suites exercise this plugin's logic, not WordPress's.
 */

define( 'ABSPATH', __DIR__ );
define( 'GF_TELEGRAM_VERSION', '1.0.0-test' );
define( 'GF_TELEGRAM_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'TG_STUB_PORT', getenv( 'TG_STUB_PORT' ) ? (int) getenv( 'TG_STUB_PORT' ) : 8799 );
define( 'TG_STUB_URL', 'http://127.0.0.1:' . TG_STUB_PORT );

// --- WordPress: errors -------------------------------------------------------------------------

class WP_Error {
	protected $code;
	protected $message;
	protected $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// --- Gravity Forms: array helpers --------------------------------------------------------------

function rgar( $array, $name, $default = null ) {
	if ( ! is_array( $array ) ) {
		return $default;
	}
	return isset( $array[ $name ] ) ? $array[ $name ] : $default;
}

function rgars( $array, $path, $default = null ) {
	$value = $array;
	foreach ( explode( '/', $path ) as $key ) {
		if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
			return $default;
		}
		$value = $value[ $key ];
	}
	return $value;
}

function rgblank( $text ) {
	return empty( $text ) && '0' !== $text && 0 !== $text;
}

// --- WordPress: strings and escaping -----------------------------------------------------------

function untrailingslashit( $string ) { return rtrim( $string, '/\\' ); }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $url, $protocols = null ) { return $url; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }

/**
 * Stands in for wp_kses at the tag level only.
 *
 * Real wp_kses also filters attributes per tag; that behavior is WordPress's and is not asserted
 * by these suites.
 */
function wp_kses( $text, $allowed_html ) {
	$allowed = '';
	foreach ( array_keys( (array) $allowed_html ) as $tag ) {
		$allowed .= "<{$tag}>";
	}
	return strip_tags( $text, $allowed );
}

// WordPress polyfills these in wp-includes/compat.php when ext-mbstring is missing, so the plugin
// may rely on them being defined. This CLI may have no mbstring, so provide the same guarantee.
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $string, $encoding = null ) {
		return (int) preg_match_all( '/./us', (string) $string );
	}
}

if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $string, $start, $length = null, $encoding = null ) {
		$chars = preg_split( '//u', (string) $string, -1, PREG_SPLIT_NO_EMPTY );
		$slice = is_null( $length ) ? array_slice( $chars, $start ) : array_slice( $chars, $start, $length );
		return implode( '', $slice );
	}
}

// --- WordPress: options ------------------------------------------------------------------------

$GLOBALS['test_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['test_options'][ $name ] );
	return true;
}

function get_bloginfo( $show = '' ) {
	return 'Test Site';
}

// --- WordPress: AJAX ---------------------------------------------------------------------------

/**
 * Thrown by the wp_send_json_* doubles so a suite can catch the response the way WordPress would
 * end the request.
 */
class Test_Json_Response extends Exception {
	public $success;
	public $data;
	public function __construct( $success, $data ) {
		parent::__construct( 'json response' );
		$this->success = $success;
		$this->data    = $data;
	}
}

function wp_send_json_success( $data = null ) {
	throw new Test_Json_Response( true, $data );
}

function wp_send_json_error( $data = null ) {
	throw new Test_Json_Response( false, $data );
}

$GLOBALS['test_nonce_valid'] = true;

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	if ( empty( $GLOBALS['test_nonce_valid'] ) ) {
		throw new Test_Json_Response( false, array( 'message' => 'bad nonce' ) );
	}
	return 1;
}

function wp_create_nonce( $action = -1 ) {
	return 'test-nonce';
}

function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

function __( $text, $domain = '' ) { return $text; }

// --- WordPress: hooks --------------------------------------------------------------------------

$GLOBALS['test_filters'] = array();

function add_test_filter( $tag, $callback ) {
	$GLOBALS['test_filters'][ $tag ][] = $callback;
}

function reset_test_filters() {
	$GLOBALS['test_filters'] = array();
}

function apply_filters( $tag, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	foreach ( rgar( $GLOBALS['test_filters'], $tag, array() ) as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $extra ) );
	}
	return $value;
}

function gf_apply_filters( $tags, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	$base  = is_array( $tags ) ? $tags[0] : $tags;
	return call_user_func_array( 'apply_filters', array_merge( array( $base, $value ), $extra ) );
}

// --- WordPress: HTTP ---------------------------------------------------------------------------

$GLOBALS['request_log']    = array();
$GLOBALS['last_sent_body'] = '';

function wp_remote_post( $url, $args = array() ) {

	$GLOBALS['request_log'][]  = $url;
	$GLOBALS['last_sent_body'] = rgar( $args, 'body' );

	$headers = '';
	foreach ( (array) rgar( $args, 'headers', array() ) as $name => $value ) {
		$headers .= "{$name}: {$value}\r\n";
	}

	$context = stream_context_create( array(
		'http' => array(
			'method'        => 'POST',
			'header'        => $headers,
			'content'       => rgar( $args, 'body' ),
			'timeout'       => rgar( $args, 'timeout', 30 ),
			'ignore_errors' => true,
		),
	) );

	$body = @file_get_contents( $url, false, $context );

	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'Could not reach ' . $url );
	}

	$code = 0;
	foreach ( (array) $http_response_header as $header ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $header, $matches ) ) {
			$code = (int) $matches[1];
		}
	}

	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}

function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }

// --- Gravity Forms: framework ------------------------------------------------------------------

class GFForms {
	public static function include_feed_addon_framework() {}
}

class GFCommon {
	/**
	 * Stands in for GF's merge tag engine: substitutes {key} from the entry and expands
	 * {all_fields}. Deliberately simple — the suites assert how this plugin treats the result,
	 * not how Gravity Forms produces it.
	 */
	public static function replace_variables( $text, $form, $entry, $url_encode = false, $esc_html = true, $nl2br = true, $format = 'html' ) {

		$GLOBALS['last_replace_format'] = $format;

		if ( false !== strpos( $text, '{all_fields}' ) ) {
			$lines = array();
			foreach ( (array) $entry as $key => $value ) {
				if ( is_numeric( $key ) ) {
					$lines[] = "Field {$key}: {$value}";
				}
			}
			$text = str_replace( '{all_fields}', implode( "\n", $lines ), $text );
		}

		foreach ( (array) $entry as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$text = str_replace( '{' . $key . '}', (string) $value, $text );
			}
		}

		return $text;
	}

	public static function get_label( $field ) {
		return isset( $field->label ) ? $field->label : '';
	}

	public static function current_user_can_any( $caps ) {
		return ! empty( $GLOBALS['test_user_can'] );
	}
}

$GLOBALS['test_user_can'] = true;

/**
 * Stands in for a GF_Field. Only the properties this plugin reads are present.
 */
class GF_Field_Double {
	public $id;
	public $type;
	public $label;
	public function __construct( $id, $type = 'fileupload', $label = '' ) {
		$this->id    = $id;
		$this->type  = $type;
		$this->label = '' === $label ? "Field {$id}" : $label;
	}
}

class GFAPI {
	public static function get_fields_by_type( $form, $types ) {
		$found = array();
		foreach ( (array) rgar( $form, 'fields', array() ) as $field ) {
			if ( in_array( $field->type, (array) $types, true ) ) {
				$found[] = $field;
			}
		}
		return $found;
	}
}

function wp_upload_dir() {
	return array(
		'basedir' => sys_get_temp_dir() . '/gftg-uploads',
		'baseurl' => 'https://example.org/wp-content/uploads',
	);
}

class GFFeedAddOn {
	public $plugin_settings = array();
	public $notes           = array();
	public $feed_errors     = array();
	public $logs            = array();
	public function init() {}
	public function add_delayed_payment_support( $args ) {}
	public function log_debug( $message ) { $this->logs[] = $message; }
	public function log_error( $message ) { $this->logs[] = $message; }
	public function get_plugin_setting( $key ) { return rgar( $this->plugin_settings, $key, '' ); }
	public function get_base_path() { return GF_TELEGRAM_PLUGIN_DIR; }
	public function add_feed_error( $message, $feed, $entry, $form ) { $this->feed_errors[] = $message; }
	public function add_note( $entry_id, $note, $type = '' ) { $this->notes[] = array( $entry_id, $note, $type ); }
	public function set_field_error( $field, $message = '' ) {}
}

// --- Assertions --------------------------------------------------------------------------------

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function check( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['passed']++;
		echo "  PASS  {$label}\n";
		return;
	}
	$GLOBALS['failed']++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
}

function summarize() {
	echo "\n----------------------------------------\n";
	echo "  {$GLOBALS['passed']} passed, {$GLOBALS['failed']} failed\n";
	exit( $GLOBALS['failed'] > 0 ? 1 : 0 );
}

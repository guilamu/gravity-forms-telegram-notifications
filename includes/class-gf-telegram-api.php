<?php

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Telegram Bot API client.
 *
 * A thin wrapper around the Bot API; no SDK, no dependencies. Every method returns either the
 * decoded `result` payload or a WP_Error built from Telegram's own error_code and description.
 *
 * @since 1.0
 *
 * @link https://core.telegram.org/bots/api
 */
class GF_Telegram_API {

	/**
	 * The default Telegram Bot API host.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URL = 'https://api.telegram.org';

	/**
	 * The placeholder written to log files in place of the bot token.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const TOKEN_MASK = '[bot-token-redacted]';

	/**
	 * The longest retry_after, in seconds, this client will wait before giving up on a 429.
	 *
	 * @since 1.0
	 *
	 * @var int
	 */
	const MAX_RETRY_AFTER = 5;

	/**
	 * The maximum length of a single message, as enforced by Telegram.
	 *
	 * Splitting longer messages is the caller's responsibility; this client sends what it is given.
	 *
	 * @since 1.0
	 *
	 * @var int
	 */
	const MAX_MESSAGE_LENGTH = 4096;

	/**
	 * The largest file the Bot API accepts as a document upload.
	 *
	 * @since 1.0
	 *
	 * @var int
	 */
	const MAX_DOCUMENT_BYTES = 52428800;

	/**
	 * The largest file the Bot API accepts as a photo upload.
	 *
	 * @since 1.0
	 *
	 * @var int
	 */
	const MAX_PHOTO_BYTES = 10485760;

	/**
	 * The bot token, as issued by @BotFather.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $bot_token;

	/**
	 * The API base URL. Only differs from the default when a self hosted Bot API server or a
	 * proxy is in use.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Initializes the API client.
	 *
	 * @since 1.0
	 *
	 * @param string $bot_token The bot token.
	 * @param string $base_url  The API base URL. Defaults to https://api.telegram.org.
	 */
	public function __construct( $bot_token, $base_url = self::DEFAULT_BASE_URL ) {
		$this->bot_token = (string) $bot_token;
		$this->base_url  = untrailingslashit( $base_url ? $base_url : self::DEFAULT_BASE_URL );
	}

	/**
	 * Returns basic information about the bot. Used to validate the token.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#getme
	 *
	 * @return array|WP_Error
	 */
	public function get_me() {
		return $this->make_request( 'getMe' );
	}

	/**
	 * Returns recent updates received by the bot.
	 *
	 * Used to discover which chats the bot can already post to, so nobody has to hunt for a chat
	 * ID by hand. This fails with a 409 while a webhook is registered for the bot: Telegram
	 * delivers updates one way or the other, never both.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#getupdates
	 *
	 * @return array|WP_Error
	 */
	public function get_updates() {
		return $this->make_request( 'getUpdates', array( 'limit' => 100, 'timeout' => 0 ) );
	}

	/**
	 * Returns the bot's current webhook registration, if any.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#getwebhookinfo
	 *
	 * @return array|WP_Error
	 */
	public function get_webhook_info() {
		return $this->make_request( 'getWebhookInfo' );
	}

	/**
	 * Sends a text message.
	 *
	 * The message is sent exactly as given: this client does not split, truncate or escape the
	 * text. Callers are expected to have prepared it for the requested parse mode.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#sendmessage
	 *
	 * @param array $args The sendMessage arguments. chat_id and text are required; any other
	 *                    parameter documented by the Bot API may be included.
	 *
	 * @return array|WP_Error The sent Message object, or an error.
	 */
	public function send_message( $args ) {

		$args = (array) $args;

		// Caught here so an obviously malformed feed does not spend an HTTP request to find out.
		if ( rgblank( rgar( $args, 'chat_id' ) ) ) {
			return new WP_Error( 'missing_chat_id', esc_html__( 'No Telegram chat ID was provided.', 'gravity-forms-telegram-notifications' ) );
		}

		if ( rgblank( rgar( $args, 'text' ) ) ) {
			return new WP_Error( 'missing_text', esc_html__( 'The message is empty.', 'gravity-forms-telegram-notifications' ) );
		}

		return $this->make_request( 'sendMessage', $args );
	}

	/**
	 * Sends a file as a document, preserving it exactly as uploaded.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#senddocument
	 *
	 * @param array  $args      The sendDocument arguments. chat_id is required.
	 * @param string $file_path Absolute path to the file to upload.
	 *
	 * @return array|WP_Error
	 */
	public function send_document( $args, $file_path ) {

		$args = (array) $args;

		if ( rgblank( rgar( $args, 'chat_id' ) ) ) {
			return new WP_Error( 'missing_chat_id', esc_html__( 'No Telegram chat ID was provided.', 'gravity-forms-telegram-notifications' ) );
		}

		return $this->make_request( 'sendDocument', $args, array( 'document' => $file_path ) );
	}

	/**
	 * Sends a file as a photo.
	 *
	 * Telegram recompresses photos, so this loses the original file. Callers should only choose it
	 * when the recipient wants an inline preview more than the original.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#sendphoto
	 *
	 * @param array  $args      The sendPhoto arguments. chat_id is required.
	 * @param string $file_path Absolute path to the image to upload.
	 *
	 * @return array|WP_Error
	 */
	public function send_photo( $args, $file_path ) {

		$args = (array) $args;

		if ( rgblank( rgar( $args, 'chat_id' ) ) ) {
			return new WP_Error( 'missing_chat_id', esc_html__( 'No Telegram chat ID was provided.', 'gravity-forms-telegram-notifications' ) );
		}

		return $this->make_request( 'sendPhoto', $args, array( 'photo' => $file_path ) );
	}

	/**
	 * Replaces the bot token with a placeholder so it is never written to the GF log.
	 *
	 * The token appears in every request URL, so this must be applied to anything derived from a
	 * request before it is logged.
	 *
	 * @since 1.0
	 *
	 * @param string $text The text to mask.
	 *
	 * @return string
	 */
	public function mask( $text ) {

		if ( rgblank( $this->bot_token ) ) {
			return (string) $text;
		}

		return str_replace( $this->bot_token, self::TOKEN_MASK, (string) $text );
	}


	// # REQUEST METHODS -----------------------------------------------------------------------------------------------

	/**
	 * Makes an API request.
	 *
	 * @since 1.0
	 *
	 * @param string $method   The Bot API method name, e.g. sendMessage.
	 * @param array  $args     The method arguments.
	 * @param array  $files    Files to upload, keyed by the parameter name Telegram expects.
	 * @param bool   $is_retry Indicates if this is the retry which follows a rate limit response.
	 *
	 * @return array|WP_Error
	 */
	protected function make_request( $method, $args = array(), $files = array(), $is_retry = false ) {

		$request_url = sprintf( '%s/bot%s/%s', $this->base_url, $this->bot_token, $method );

		if ( ! empty( $files ) ) {

			$boundary = '----GFTelegram' . md5( uniqid( '', true ) );
			$body     = $this->build_multipart_body( $args, $files, $boundary );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$content_type = 'multipart/form-data; boundary=' . $boundary;

		} else {

			// The request is sent as JSON rather than form encoded. Nested parameters such as
			// reply_markup would otherwise be flattened by http_build_query into bracketed keys
			// that Telegram rejects, and booleans would arrive as "1" and "".
			$body         = wp_json_encode( $this->prepare_args( $args ) );
			$content_type = 'application/json';

			if ( false === $body ) {
				return new WP_Error( 'invalid_request', esc_html__( 'The message could not be encoded for sending.', 'gravity-forms-telegram-notifications' ) );
			}
		}

		$request_args = array(
			'method'  => 'POST',
			'body'    => $body,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => $content_type,
			),
			/**
			 * Sets the HTTP timeout, in seconds, for the request.
			 *
			 * Uploads get considerably longer than messages: pushing a large attachment over a
			 * slow connection will not finish within the time a text message needs. Feed
			 * processing runs in the background, so the wait does not delay a submission.
			 *
			 * @since 1.0
			 *
			 * @param int    $request_timeout The timeout limit, in seconds.
			 * @param string $request_url     The request URL.
			 */
			'timeout' => apply_filters( 'http_request_timeout', empty( $files ) ? 30 : 120, $request_url ),
		);

		$response = wp_remote_post( $request_url, $request_args );

		// A transport level failure: no response was received at all.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$response->get_error_code(),
				$this->mask( $response->get_error_message() ),
				$this->mask( (string) $response->get_error_data() )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		// Anything that is not a Telegram JSON envelope: a proxy error page, an HTML 502, etc.
		if ( ! is_array( $response_data ) || ! isset( $response_data['ok'] ) ) {
			return new WP_Error(
				'invalid_response',
				sprintf(
					/* translators: %d: The HTTP response code. */
					esc_html__( 'The API returned an unexpected response (HTTP %d).', 'gravity-forms-telegram-notifications' ),
					$response_code
				),
				$this->mask( substr( $response_body, 0, 500 ) )
			);
		}

		if ( ! empty( $response_data['ok'] ) ) {
			return rgar( $response_data, 'result' );
		}

		$error_code  = (int) rgar( $response_data, 'error_code', $response_code );
		$description = (string) rgar( $response_data, 'description' );
		$parameters  = (array) rgar( $response_data, 'parameters', array() );

		// Telegram asks callers to back off; honor a single short retry.
		$retry_after = (int) rgar( $parameters, 'retry_after' );
		if ( 429 === $error_code && ! $is_retry && $retry_after > 0 && $retry_after <= self::MAX_RETRY_AFTER ) {
			sleep( $retry_after );

			return $this->make_request( $method, $args, $files, true );
		}

		return new WP_Error( $error_code, $this->mask( $description ), $parameters );
	}

	/**
	 * Assembles a multipart/form-data request body.
	 *
	 * WordPress has no helper for this: wp_remote_post() sends an array body as form encoded data
	 * and offers no way to attach a file, so the body is built by hand.
	 *
	 * @since 1.0
	 *
	 * @param array  $args     The method arguments.
	 * @param array  $files    Files to upload, keyed by the parameter name Telegram expects.
	 * @param string $boundary The multipart boundary.
	 *
	 * @return string|WP_Error
	 */
	protected function build_multipart_body( $args, $files, $boundary ) {

		$body = '';

		foreach ( $this->prepare_args( $args ) as $name => $value ) {

			// Everything crosses the wire as text here, so the types JSON would have carried have
			// to be spelled out: Telegram reads "true" and "false", not "1" and "".
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}

		foreach ( (array) $files as $name => $path ) {

			if ( ! is_readable( $path ) ) {
				return new WP_Error(
					'unreadable_file',
					sprintf(
						/* translators: %s: The file name. */
						esc_html__( 'The file %s could not be read.', 'gravity-forms-telegram-notifications' ),
						basename( $path )
					)
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $path );

			if ( false === $contents ) {
				return new WP_Error(
					'unreadable_file',
					sprintf(
						/* translators: %s: The file name. */
						esc_html__( 'The file %s could not be read.', 'gravity-forms-telegram-notifications' ),
						basename( $path )
					)
				);
			}

			// A quote in the file name would end the header field early.
			$filename = str_replace( array( '"', "\r", "\n" ), '', basename( $path ) );

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . "\r\n";
			$body .= "Content-Type: application/octet-stream\r\n\r\n";
			$body .= $contents . "\r\n";
		}

		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}

	/**
	 * Removes arguments which have no value so optional parameters can be passed unconditionally.
	 *
	 * Only the top level is filtered. Nested structures are built by this plugin rather than by
	 * user input, and filtering them would risk turning a JSON array into a JSON object by leaving
	 * gaps in its keys.
	 *
	 * @since 1.0
	 *
	 * @param array $args The arguments to filter.
	 *
	 * @return array
	 */
	protected function prepare_args( $args ) {

		$prepared = array();

		foreach ( (array) $args as $key => $value ) {

			if ( is_null( $value ) ) {
				continue;
			}

			$prepared[ $key ] = $value;
		}

		return $prepared;
	}
}

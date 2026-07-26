<?php

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Turns a feed's message template into text Telegram will accept.
 *
 * The important property here is that submitted values are escaped while the template around them
 * is not. An admin can write <b>New entry</b> in the message box and have it render as bold, while
 * a visitor who types <b>New entry</b> into a form field gets those characters shown literally.
 * Escaping the whole rendered string would break the first case; escaping nothing is how a stray
 * underscore in a submission turns into a 400 from Telegram and a notification that never arrives.
 *
 * @since 1.0
 */
class GF_Telegram_Formatter {

	/**
	 * Telegram's HTML parse mode.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const PARSE_MODE_HTML = 'HTML';

	/**
	 * Telegram's MarkdownV2 parse mode.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const PARSE_MODE_MARKDOWN = 'MarkdownV2';

	/**
	 * No parse mode: Telegram treats the message as literal text.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const PARSE_MODE_NONE = '';

	/**
	 * Characters MarkdownV2 reserves, which must be backslash escaped inside values.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const MARKDOWN_RESERVED = '_*[]()~`>#+-=|{}.!\\';

	/**
	 * Characters held back from the split limit so the tags added when balancing a chunk cannot
	 * push it over.
	 *
	 * @since 1.0
	 *
	 * @var int
	 */
	const HTML_SPLIT_RESERVE = 100;

	/**
	 * Returns the parse modes offered in the feed settings.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_parse_mode_choices() {

		return array(
			array(
				'value' => self::PARSE_MODE_HTML,
				'label' => esc_html__( 'HTML', 'gravity-forms-telegram-notifications' ),
			),
			array(
				'value' => self::PARSE_MODE_MARKDOWN,
				'label' => esc_html__( 'MarkdownV2', 'gravity-forms-telegram-notifications' ),
			),
			array(
				'value' => self::PARSE_MODE_NONE,
				'label' => esc_html__( 'None (plain text)', 'gravity-forms-telegram-notifications' ),
			),
		);
	}

	/**
	 * Reduces any stored value to a parse mode this class understands.
	 *
	 * @since 1.0
	 *
	 * @param string $parse_mode The stored parse mode.
	 *
	 * @return string
	 */
	public static function sanitize_parse_mode( $parse_mode ) {

		$known = array( self::PARSE_MODE_HTML, self::PARSE_MODE_MARKDOWN, self::PARSE_MODE_NONE );

		return in_array( $parse_mode, $known, true ) ? $parse_mode : self::PARSE_MODE_NONE;
	}

	/**
	 * The HTML Telegram accepts. Anything else in a message is a hard 400, so everything outside
	 * this list is stripped before sending.
	 *
	 * @since 1.0
	 *
	 * @link https://core.telegram.org/bots/api#html-style
	 *
	 * @return array
	 */
	public static function get_allowed_html() {

		return array(
			'b'          => array(),
			'strong'     => array(),
			'i'          => array(),
			'em'         => array(),
			'u'          => array(),
			'ins'        => array(),
			's'          => array(),
			'strike'     => array(),
			'del'        => array(),
			'a'          => array( 'href' => true ),
			'code'       => array( 'class' => true ),
			'pre'        => array(),
			'blockquote' => array( 'expandable' => true ),
			'span'       => array( 'class' => true ),
			'tg-spoiler' => array(),
			'tg-emoji'   => array( 'emoji-id' => true ),
		);
	}

	/**
	 * Renders a message template for one submission.
	 *
	 * The template is split into literal text and merge tags. Each merge tag is resolved on its
	 * own and escaped for the parse mode; the literal text between them is left exactly as the
	 * admin wrote it.
	 *
	 * @since 1.0
	 *
	 * @param string $template   The message template from the feed.
	 * @param array  $form       The form object.
	 * @param array  $entry      The entry object.
	 * @param string $parse_mode The parse mode the message will be sent with.
	 *
	 * @return string
	 */
	public static function render( $template, $form, $entry, $parse_mode ) {

		$parse_mode = self::sanitize_parse_mode( $parse_mode );

		// The capture group means the delimiters are kept, landing on the odd indexes.
		$segments = preg_split( '/(\{[^{}]+\})/', (string) $template, -1, PREG_SPLIT_DELIM_CAPTURE );

		$rendered = '';

		foreach ( (array) $segments as $index => $segment ) {

			if ( '' === $segment ) {
				continue;
			}

			// Even indexes are the admin's own text, which keeps whatever formatting they wrote.
			if ( 0 === $index % 2 ) {
				$rendered .= $segment;
				continue;
			}

			// The text format is what stops {all_fields} expanding into an HTML table.
			$value = GFCommon::replace_variables( $segment, $form, $entry, false, false, false, 'text' );

			$rendered .= self::escape( $value, $parse_mode );
		}

		if ( self::PARSE_MODE_HTML === $parse_mode ) {
			$rendered = wp_kses( $rendered, self::get_allowed_html() );
		}

		return trim( $rendered );
	}

	/**
	 * Escapes a submitted value so it cannot be read as formatting.
	 *
	 * @since 1.0
	 *
	 * @param string $value      The value to escape.
	 * @param string $parse_mode The parse mode the message will be sent with.
	 *
	 * @return string
	 */
	public static function escape( $value, $parse_mode ) {

		$value = (string) $value;

		switch ( self::sanitize_parse_mode( $parse_mode ) ) {

			case self::PARSE_MODE_HTML:
				// Quotes are escaped too: a merge tag may sit inside an href in the template.
				return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );

			case self::PARSE_MODE_MARKDOWN:
				return preg_replace( '/([' . preg_quote( self::MARKDOWN_RESERVED, '/' ) . '])/u', '\\\\$1', $value );

			default:
				// Telegram parses nothing, so there is nothing to escape.
				return $value;
		}
	}

	/**
	 * Returns a URL which is safe to attach to an inline button, or an empty string.
	 *
	 * A button whose URL Telegram does not accept fails the entire sendMessage call, taking the
	 * message down with it, so anything questionable is dropped rather than sent.
	 *
	 * @since 1.0
	 *
	 * @param string $url The URL to check.
	 *
	 * @return string
	 */
	public static function sanitize_button_url( $url ) {

		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$allowed_schemes = array( 'http', 'https', 'tg' );
		$scheme          = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		// Telegram requires an absolute URL; a bare path or a javascript: URL is not usable.
		if ( ! in_array( $scheme, $allowed_schemes, true ) ) {
			return '';
		}

		return esc_url_raw( $url, $allowed_schemes );
	}

	/**
	 * Splits a message into pieces Telegram will accept.
	 *
	 * Breaks on line boundaries wherever possible so the result still reads naturally, and only
	 * cuts mid-line when a single line is itself too long.
	 *
	 * @since 1.0
	 *
	 * @param string $text       The rendered message.
	 * @param string $parse_mode The parse mode the message will be sent with.
	 * @param int    $limit      The maximum length of one message.
	 *
	 * @return array
	 */
	public static function split( $text, $parse_mode, $limit = 0 ) {

		$parse_mode = self::sanitize_parse_mode( $parse_mode );
		$limit      = $limit > 0 ? (int) $limit : GF_Telegram_API::MAX_MESSAGE_LENGTH;
		$text       = (string) $text;

		if ( '' === $text ) {
			return array();
		}

		// Balancing reopens tags at the start of a chunk and closes them at the end, which makes
		// the chunk longer; hold back room for that rather than overshooting the limit.
		$split_limit = self::PARSE_MODE_HTML === $parse_mode
			? max( 1, $limit - self::HTML_SPLIT_RESERVE )
			: $limit;

		if ( self::length( $text ) <= $split_limit ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';

		foreach ( explode( "\n", $text ) as $line ) {

			$candidate = '' === $current ? $line : $current . "\n" . $line;

			if ( self::length( $candidate ) <= $split_limit ) {
				$current = $candidate;
				continue;
			}

			if ( '' !== $current ) {
				$chunks[] = $current;
				$current  = '';
			}

			// A single line longer than the limit has to be cut somewhere.
			while ( self::length( $line ) > $split_limit ) {
				$head     = self::truncate( $line, $split_limit );
				$chunks[] = $head;
				$line     = mb_substr( $line, mb_strlen( $head ) );
			}

			$current = $line;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		if ( self::PARSE_MODE_HTML === $parse_mode ) {
			$chunks = self::balance_html( $chunks );
		}

		return $chunks;
	}

	/**
	 * Closes tags left open at the end of a chunk and reopens them at the start of the next.
	 *
	 * Without this, a bold span that happens to straddle a split arrives as an unclosed tag and
	 * Telegram rejects the whole message.
	 *
	 * @since 1.0
	 *
	 * @param array $chunks The message chunks.
	 *
	 * @return array
	 */
	protected static function balance_html( $chunks ) {

		$balanced = array();
		$open     = array();

		foreach ( $chunks as $chunk ) {

			// Reopen whatever was still open when the previous chunk ended.
			$prefix = '';
			foreach ( $open as $tag ) {
				$prefix .= $tag['open'];
			}

			if ( preg_match_all( '#<(/?)([a-z][a-z0-9-]*)([^>]*)>#i', $chunk, $matches, PREG_SET_ORDER ) ) {

				foreach ( $matches as $match ) {

					$is_closing = '/' === $match[1];
					$name       = strtolower( $match[2] );

					if ( ! $is_closing ) {
						$open[] = array( 'name' => $name, 'open' => $match[0] );
						continue;
					}

					// Unwind to the matching opener; Telegram's tags are all paired.
					for ( $i = count( $open ) - 1; $i >= 0; $i-- ) {
						if ( $open[ $i ]['name'] === $name ) {
							array_splice( $open, $i, 1 );
							break;
						}
					}
				}
			}

			$suffix = '';
			foreach ( array_reverse( $open ) as $tag ) {
				$suffix .= '</' . $tag['name'] . '>';
			}

			$balanced[] = $prefix . $chunk . $suffix;
		}

		return $balanced;
	}

	/**
	 * Returns the length of a string the way Telegram counts it.
	 *
	 * Telegram counts UTF-16 code units, so anything outside the basic multilingual plane — most
	 * emoji — counts as two rather than one.
	 *
	 * @since 1.0
	 *
	 * @param string $text The text to measure.
	 *
	 * @return int
	 */
	public static function length( $text ) {

		$text = (string) $text;

		return mb_strlen( $text ) + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $text );
	}

	/**
	 * Returns the longest leading part of a string which fits the given length.
	 *
	 * @since 1.0
	 *
	 * @param string $text  The text to cut.
	 * @param int    $limit The maximum length, counted as Telegram counts it.
	 *
	 * @return string
	 */
	protected static function truncate( $text, $limit ) {

		$characters = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$result     = '';
		$length     = 0;

		foreach ( (array) $characters as $character ) {

			$width = self::length( $character );

			if ( $length + $width > $limit ) {
				break;
			}

			$result .= $character;
			$length += $width;
		}

		return $result;
	}
}

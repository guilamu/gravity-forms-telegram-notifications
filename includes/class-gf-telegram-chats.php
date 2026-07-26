<?php

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Keeps track of the chats the bot has been added to.
 *
 * Telegram has no "list my chats" endpoint. The only way a bot learns about a chat is by receiving
 * an update from it, so discovery means reading recent updates and remembering what turns up. That
 * makes the list a cache rather than a source of truth: it holds what has been seen, and a chat
 * the bot has never received a message from will not appear until someone writes to it.
 *
 * @since 1.0
 */
class GF_Telegram_Chats {

	/**
	 * The option the discovered chats are stored in.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	const OPTION_NAME = 'gravityformstelegram_chats';

	/**
	 * The update properties which can carry a chat.
	 *
	 * @since 1.0
	 *
	 * @var array
	 */
	protected static $update_types = array(
		'message',
		'edited_message',
		'channel_post',
		'edited_channel_post',
		'my_chat_member',
	);

	/**
	 * Returns every known chat, keyed by chat ID.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_all() {

		$chats = get_option( self::OPTION_NAME, array() );

		return is_array( $chats ) ? $chats : array();
	}

	/**
	 * Stores the known chats.
	 *
	 * @since 1.0
	 *
	 * @param array $chats The chats to store, keyed by chat ID.
	 */
	public static function save( $chats ) {

		// Not autoloaded: this is only read on the settings and feed pages.
		update_option( self::OPTION_NAME, (array) $chats, false );
	}

	/**
	 * Forgets every known chat.
	 *
	 * @since 1.0
	 */
	public static function clear() {

		delete_option( self::OPTION_NAME );
	}

	/**
	 * Adds newly seen chats to the ones already known.
	 *
	 * Existing entries are replaced so a renamed group updates rather than appearing twice.
	 *
	 * @since 1.0
	 *
	 * @param array $known The chats already stored.
	 * @param array $found The chats just discovered.
	 *
	 * @return array
	 */
	public static function merge( $known, $found ) {

		// Assigned key by key rather than with array_merge: a chat ID is a numeric string, which
		// PHP stores as an integer key, and array_merge renumbers integer keys instead of keeping
		// them. That would turn every rediscovery into a fresh set of duplicates.
		$merged = (array) $known;

		foreach ( (array) $found as $key => $chat ) {
			$merged[ $key ] = $chat;
		}

		uasort( $merged, function ( $a, $b ) {
			return strcasecmp( (string) rgar( $a, 'title' ), (string) rgar( $b, 'title' ) );
		} );

		return $merged;
	}

	/**
	 * Pulls the chats out of a getUpdates response.
	 *
	 * @since 1.0
	 *
	 * @param array $updates The array of Update objects returned by Telegram.
	 *
	 * @return array Chats keyed by chat ID.
	 */
	public static function extract_from_updates( $updates ) {

		$chats = array();

		foreach ( (array) $updates as $update ) {

			foreach ( self::$update_types as $type ) {

				$chat = rgars( (array) $update, $type . '/chat' );

				if ( ! is_array( $chat ) || rgblank( rgar( $chat, 'id' ) ) ) {
					continue;
				}

				$id = (string) $chat['id'];

				$chats[ $id ] = array(
					'id'    => $id,
					'type'  => (string) rgar( $chat, 'type' ),
					'title' => self::build_title( $chat ),
				);
			}
		}

		return $chats;
	}

	/**
	 * Works out a readable name for a chat.
	 *
	 * Groups and channels carry a title; private chats carry a person's name instead.
	 *
	 * @since 1.0
	 *
	 * @param array $chat The Chat object from Telegram.
	 *
	 * @return string
	 */
	protected static function build_title( $chat ) {

		$title = trim( (string) rgar( $chat, 'title' ) );

		if ( '' !== $title ) {
			return $title;
		}

		$name = trim( rgar( $chat, 'first_name', '' ) . ' ' . rgar( $chat, 'last_name', '' ) );

		if ( '' !== $name ) {
			return $name;
		}

		$username = trim( (string) rgar( $chat, 'username' ) );

		return '' !== $username ? '@' . $username : '';
	}

	/**
	 * Returns the label shown for a chat in the settings and feed dropdowns.
	 *
	 * @since 1.0
	 *
	 * @param array $chat A stored chat.
	 *
	 * @return string
	 */
	public static function describe( $chat ) {

		$id    = (string) rgar( $chat, 'id' );
		$title = trim( (string) rgar( $chat, 'title' ) );

		if ( '' === $title ) {
			return $id;
		}

		return sprintf( '%s (%s)', $title, $id );
	}
}

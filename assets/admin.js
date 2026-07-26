/* global jQuery, ajaxurl, gf_telegram_admin_strings */

/**
 * Settings page tools: chat discovery and the test message.
 */
( function ( $ ) {
	'use strict';

	var strings = window.gf_telegram_admin_strings || {};

	var actions = {
		discover: 'gf_telegram_discover_chats',
		test: 'gf_telegram_send_test'
	};

	function setNotice( $result, type, message ) {
		$result
			.attr( 'class', 'gf-telegram-tools__result notice notice-alt inline notice-' + type )
			.html( '<p>' + message + '</p>' );
	}

	function run( $button ) {
		var action = actions[ $button.data( 'gf-telegram-action' ) ];

		if ( ! action ) {
			return;
		}

		var $tools = $button.closest( '.gf-telegram-tools' );
		var $result = $tools.find( '[data-gf-telegram-result]' );
		var $chats = $tools.find( '[data-gf-telegram-chats]' );
		var label = $button.text();

		$tools.find( 'button' ).prop( 'disabled', true );
		$button.text( strings.working || 'Working...' );
		$result.attr( 'class', 'gf-telegram-tools__result' ).empty();

		$.post( ajaxurl, {
			action: action,
			nonce: strings.nonce
		} )
			.done( function ( response ) {
				// admin-ajax answers with a bare 0 when no handler is registered for the action,
				// and -1 when it rejects the nonce. Neither is a JSON envelope, so there is no
				// message to show and nothing was ever logged — say so instead of sending someone
				// to read an empty log.
				if ( ! response || typeof response !== 'object' ) {
					setNotice( $result, 'error', strings.rejected || 'The request was rejected.' );
					return;
				}

				var data = response.data ? response.data : {};
				var message = data.message || strings.failed;

				setNotice( $result, response && response.success ? 'success' : 'error', message );

				// The server returns the refreshed table so the list never disagrees with storage.
				if ( typeof data.markup === 'string' ) {
					$chats.html( data.markup );
				}
			} )
			.fail( function () {
				setNotice( $result, 'error', strings.failed || 'The request failed.' );
			} )
			.always( function () {
				$tools.find( 'button' ).prop( 'disabled', false );
				$button.text( label );
			} );
	}

	$( document ).on( 'click', '.gf-telegram-tools [data-gf-telegram-action]', function ( event ) {
		event.preventDefault();
		run( $( this ) );
	} );
}( jQuery ) );

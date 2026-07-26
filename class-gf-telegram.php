<?php

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

GFForms::include_feed_addon_framework();

/**
 * Gravity Forms Telegram Notifications.
 *
 * @since 1.0
 */
class GFTelegram extends GFFeedAddOn {

	/**
	 * Contains an instance of this class, if available.
	 *
	 * @since 1.0
	 *
	 * @var null|GFTelegram
	 */
	private static $_instance = null;

	/**
	 * Defines the version of the add-on.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_version = GF_TELEGRAM_VERSION;

	/**
	 * Defines the minimum Gravity Forms version required.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5';

	/**
	 * Defines the plugin slug. Used for the settings option name, the feed table and the menu.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_slug = 'gravityformstelegram';

	/**
	 * Defines the main plugin file, relative to the plugins folder.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_path = 'gravity-forms-telegram-notifications/gravity-forms-telegram-notifications.php';

	/**
	 * Defines the full path to this class file.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_full_path = __FILE__;

	/**
	 * Defines the URL where this add-on can be found.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_url = 'https://github.com/guilamu/gravity-forms-telegram-notifications';

	/**
	 * Defines the title of this add-on.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_title = 'Gravity Forms Telegram Notifications';

	/**
	 * Defines the short title of the add-on.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_short_title = 'Telegram';

	/**
	 * Defines the capability needed to access the add-on settings page.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_telegram';

	/**
	 * Defines the capability needed to access the add-on form settings page.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_telegram';

	/**
	 * Defines the capability needed to uninstall the add-on.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_telegram_uninstall';

	/**
	 * Defines the capabilities needed for the add-on.
	 *
	 * @since 1.0
	 *
	 * @var array
	 */
	protected $_capabilities = array( 'gravityforms_telegram', 'gravityforms_telegram_uninstall' );

	/**
	 * Enables background feed processing so the HTTP call to Telegram does not delay submission.
	 *
	 * @since 1.0
	 *
	 * @var bool
	 */
	protected $_async_feed_processing = true;

	/**
	 * Contains an instance of the API client once the token has been validated.
	 *
	 * Null when validation has not run yet, false when the token is missing or invalid.
	 *
	 * @since 1.0
	 *
	 * @var null|bool|GF_Telegram_API
	 */
	protected $api = null;

	/**
	 * Contains the getMe payload for the configured bot, once validated.
	 *
	 * @since 1.0
	 *
	 * @var array
	 */
	protected $bot = array();

	/**
	 * Get an instance of this class.
	 *
	 * @since 1.0
	 *
	 * @return GFTelegram
	 */
	public static function get_instance() {

		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Registers the delayed payment support.
	 *
	 * @since 1.0
	 */
	public function init() {

		parent::init();

		$this->add_delayed_payment_support(
			array(
				'option_label' => esc_html__( 'Send the Telegram notification only when a payment is received.', 'gravity-forms-telegram-notifications' ),
			)
		);
	}

	/**
	 * Removes the data this add-on stores outside the framework's own tables.
	 *
	 * Gravity Forms removes the plugin settings and the feeds itself; the discovered chats are
	 * ours to clean up.
	 *
	 * @since 1.0
	 */
	public function uninstall() {

		parent::uninstall();

		GF_Telegram_Chats::clear();
	}

	/**
	 * Registers the settings page AJAX handlers.
	 *
	 * These belong here rather than in init_admin(): the framework picks one context per request,
	 * and a request to admin-ajax.php gets init_ajax(), never init_admin(). Registered in the wrong
	 * one, the handlers do not exist when the settings page calls them, and admin-ajax answers with
	 * a bare 0 — no error, nothing logged.
	 *
	 * @since 1.0
	 */
	public function init_ajax() {

		parent::init_ajax();

		add_action( 'wp_ajax_gf_telegram_discover_chats', array( $this, 'ajax_discover_chats' ) );
		add_action( 'wp_ajax_gf_telegram_send_test', array( $this, 'ajax_send_test' ) );
	}

	/**
	 * Registers the settings page script.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function scripts() {

		return array_merge(
			parent::scripts(),
			array(
				array(
					'handle'  => 'gf_telegram_admin',
					'src'     => $this->get_base_url() . '/assets/admin.js',
					'version' => $this->_version,
					'deps'    => array( 'jquery' ),
					'enqueue' => array(
						array( 'admin_page' => array( 'plugin_settings' ) ),
					),
					'strings' => array(
						'nonce'      => wp_create_nonce( 'gf_telegram_admin' ),
						'working'    => esc_html__( 'Working…', 'gravity-forms-telegram-notifications' ),
						'findChats'  => esc_html__( 'Find my chats', 'gravity-forms-telegram-notifications' ),
						'sendTest'   => esc_html__( 'Send a test message', 'gravity-forms-telegram-notifications' ),
						'failed'     => esc_html__( 'The request failed. Check the Gravity Forms log for details.', 'gravity-forms-telegram-notifications' ),
						'rejected'   => esc_html__( 'The request was rejected before it reached the add-on. Reload the page and try again.', 'gravity-forms-telegram-notifications' ),
						'noChats'    => esc_html__( 'No chats found yet. Message your bot, or add it to a group, then try again.', 'gravity-forms-telegram-notifications' ),
					),
				),
			)
		);
	}

	/**
	 * Registers the settings page styles.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function styles() {

		return array_merge(
			parent::styles(),
			array(
				array(
					'handle'  => 'gf_telegram_admin',
					'src'     => $this->get_base_url() . '/assets/admin.css',
					'version' => $this->_version,
					'enqueue' => array(
						array( 'admin_page' => array( 'plugin_settings' ) ),
					),
				),
			)
		);
	}

	/**
	 * Returns the icon displayed in the plugin and form settings menus.
	 *
	 * Gravity Forms has no core icon for Telegram, so the markup is supplied inline.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_menu_icon() {

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 2 2 9.5l7.5 3z"/><path d="M22 2 9.5 12.5 12.5 22z"/></svg>';
	}


	// # PLUGIN SETTINGS -----------------------------------------------------------------------------------------------

	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {

		$connection_fields = array();

		// When the token comes from the constant there is nothing to edit, so the field is omitted
		// entirely; the section description explains where the token is coming from.
		if ( ! $this->is_token_defined_by_constant() ) {

			$connection_fields[] = array(
				'name'              => 'botToken',
				'label'             => esc_html__( 'Bot Token', 'gravity-forms-telegram-notifications' ),
				'type'              => 'text',
				'class'             => 'large',
				'required'          => true,
				'feedback_callback' => array( $this, 'initialize_api' ),
				'description'       => esc_html__( 'The token @BotFather gave you, in the form 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ.', 'gravity-forms-telegram-notifications' ),
				'tooltip'           => sprintf(
					'<h6>%s</h6>%s',
					esc_html__( 'Bot Token', 'gravity-forms-telegram-notifications' ),
					esc_html__( 'Anyone holding this token controls the bot. It can also be defined in wp-config.php as GF_TELEGRAM_BOT_TOKEN to keep it out of the database.', 'gravity-forms-telegram-notifications' )
				),
			);
		}

		$connection_fields[] = array(
			'name'        => 'defaultChatIds',
			'label'       => esc_html__( 'Default Recipients', 'gravity-forms-telegram-notifications' ),
			'type'        => 'textarea',
			'class'       => 'medium',
			'rows'        => 4,
			'description' => esc_html__( 'One chat ID or public @username per line. Feeds that do not name their own recipient are sent here.', 'gravity-forms-telegram-notifications' ),
			'tooltip'     => sprintf(
				'<h6>%s</h6>%s',
				esc_html__( 'Default Recipients', 'gravity-forms-telegram-notifications' ),
				esc_html__( 'A chat ID looks like 123456789 for a private chat or -1001234567890 for a group or channel. The bot must have been started by the user, or added to the group or channel, before it can post there. An @username works for a public channel or group only: a person is always reached by their numeric ID, never by their @username.', 'gravity-forms-telegram-notifications' )
			),
		);

		$connection_fields[] = array(
			'name'  => 'telegramTools',
			'label' => esc_html__( 'Chats', 'gravity-forms-telegram-notifications' ),
			'type'  => 'telegram_tools',
		);

		return array(
			array(
				'title'       => esc_html__( 'Telegram Bot Connection', 'gravity-forms-telegram-notifications' ),
				'description' => $this->get_connection_description(),
				'fields'      => $connection_fields,
			),
			array(
				'title'       => esc_html__( 'Advanced', 'gravity-forms-telegram-notifications' ),
				'description' => esc_html__( 'Leave these settings alone unless Telegram is unreachable from your server.', 'gravity-forms-telegram-notifications' ),
				'fields'      => array(
					array(
						'name'                => 'apiBaseUrl',
						'label'               => esc_html__( 'API Base URL', 'gravity-forms-telegram-notifications' ),
						'type'                => 'text',
						'class'               => 'large',
						'placeholder'         => GF_Telegram_API::DEFAULT_BASE_URL,
						'validation_callback' => array( $this, 'validate_api_base_url' ),
						'description'         => sprintf(
							/* translators: %s: The default API base URL, wrapped in a code tag. */
							esc_html__( 'Defaults to %s. Change this only when using a self-hosted Bot API server or a proxy that forwards to Telegram.', 'gravity-forms-telegram-notifications' ),
							'<code>' . esc_html( GF_Telegram_API::DEFAULT_BASE_URL ) . '</code>'
						),
					),
				),
			),
		);
	}

	/**
	 * Builds the description for the connection section, including the setup steps and, once the
	 * token is valid, the identity of the connected bot.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_connection_description() {

		$steps = sprintf(
			/* translators: 1: Opening anchor tag for BotFather. 2: Closing anchor tag. 3: The /newbot command, wrapped in a code tag. */
			esc_html__( 'Open %1$s@BotFather%2$s in Telegram, send %3$s and follow the prompts, then paste the token it gives you below.', 'gravity-forms-telegram-notifications' ),
			'<a href="https://t.me/botfather" target="_blank" rel="noopener noreferrer">',
			'</a>',
			'<code>/newbot</code>'
		);

		if ( $this->is_token_defined_by_constant() ) {
			$steps .= ' ' . sprintf(
				/* translators: %s: The GF_TELEGRAM_BOT_TOKEN constant name, wrapped in a code tag. */
				esc_html__( 'The token is currently being read from the %s constant, so it cannot be edited here.', 'gravity-forms-telegram-notifications' ),
				'<code>GF_TELEGRAM_BOT_TOKEN</code>'
			);
		}

		if ( ! $this->initialize_api() ) {
			return $steps;
		}

		$username = rgar( $this->bot, 'username' );

		if ( rgblank( $username ) ) {
			return $steps;
		}

		return $steps . '<br /><br />' . sprintf(
			/* translators: %s: The bot username, linked to the bot, in bold. */
			esc_html__( 'Connected as %s.', 'gravity-forms-telegram-notifications' ),
			sprintf(
				'<strong><a href="https://t.me/%1$s" target="_blank" rel="noopener noreferrer">@%1$s</a></strong>',
				esc_attr( $username )
			)
		);
	}

	/**
	 * Renders the chat discovery and test message tools.
	 *
	 * The chat list is read fresh from the database rather than from $field: Gravity Forms builds
	 * the settings array before a save is processed, so anything embedded in $field would be one
	 * save behind.
	 *
	 * @since 1.0
	 *
	 * @param array $field The field properties.
	 * @param bool  $echo  Whether to print the markup.
	 *
	 * @return string
	 */
	public function settings_telegram_tools( $field, $echo = true ) {

		$chats = GF_Telegram_Chats::get_all();

		ob_start();
		?>
		<div class="gf-telegram-tools">
			<p class="gf-telegram-tools__actions">
				<button type="button" class="button" data-gf-telegram-action="discover">
					<?php esc_html_e( 'Find my chats', 'gravity-forms-telegram-notifications' ); ?>
				</button>
				<button type="button" class="button" data-gf-telegram-action="test">
					<?php esc_html_e( 'Send a test message', 'gravity-forms-telegram-notifications' ); ?>
				</button>
			</p>
			<p class="gf-telegram-tools__hint description">
				<?php esc_html_e( 'Save your token first. Telegram only tells a bot about chats it has already heard from, so message the bot or add it to your group before searching.', 'gravity-forms-telegram-notifications' ); ?>
			</p>
			<div class="gf-telegram-tools__result" data-gf-telegram-result aria-live="polite"></div>
			<div data-gf-telegram-chats>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.
				echo $this->get_chats_markup( $chats );
				?>
			</div>
		</div>
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}

		return $html;
	}

	/**
	 * Returns the markup for the known chats table.
	 *
	 * @since 1.0
	 *
	 * @param array $chats The known chats.
	 *
	 * @return string
	 */
	public function get_chats_markup( $chats ) {

		if ( empty( $chats ) ) {
			return '';
		}

		$html = '<table class="gf-telegram-chats widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Chat', 'gravity-forms-telegram-notifications' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'gravity-forms-telegram-notifications' ) . '</th>'
			. '<th>' . esc_html__( 'Chat ID', 'gravity-forms-telegram-notifications' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( (array) $chats as $chat ) {
			$title = trim( (string) rgar( $chat, 'title' ) );

			$html .= '<tr>'
				. '<td>' . esc_html( '' !== $title ? $title : __( '(no name)', 'gravity-forms-telegram-notifications' ) ) . '</td>'
				. '<td>' . esc_html( rgar( $chat, 'type' ) ) . '</td>'
				. '<td><code>' . esc_html( rgar( $chat, 'id' ) ) . '</code></td>'
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}


	// # AJAX ----------------------------------------------------------------------------------------------------------

	/**
	 * Verifies the request came from someone allowed to configure the add-on.
	 *
	 * @since 1.0
	 */
	protected function verify_ajax_request() {

		check_ajax_referer( 'gf_telegram_admin', 'nonce' );

		if ( ! GFCommon::current_user_can_any( $this->_capabilities_settings_page ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to do that.', 'gravity-forms-telegram-notifications' ) ) );
		}
	}

	/**
	 * Looks for chats the bot can post to and stores what it finds.
	 *
	 * @since 1.0
	 */
	public function ajax_discover_chats() {

		$this->verify_ajax_request();

		if ( ! $this->initialize_api() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Save a valid bot token first.', 'gravity-forms-telegram-notifications' ) ) );
		}

		$updates = $this->api->get_updates();

		if ( is_wp_error( $updates ) ) {
			wp_send_json_error( array( 'message' => $this->describe_updates_error( $updates ) ) );
		}

		$found = GF_Telegram_Chats::extract_from_updates( $updates );
		$chats = GF_Telegram_Chats::merge( GF_Telegram_Chats::get_all(), $found );

		GF_Telegram_Chats::save( $chats );

		$this->log_debug( __METHOD__ . sprintf( '(): Found %d chat(s) in %d update(s).', count( $found ), count( (array) $updates ) ) );

		wp_send_json_success(
			array(
				'message' => empty( $chats )
					? esc_html__( 'No chats found yet. Message your bot, or add it to a group, then try again.', 'gravity-forms-telegram-notifications' )
					: sprintf(
						/* translators: %d: The number of chats found. */
						esc_html( _n( '%d chat is now known to this site.', '%d chats are now known to this site.', count( $chats ), 'gravity-forms-telegram-notifications' ) ),
						count( $chats )
					),
				'markup'  => $this->get_chats_markup( $chats ),
			)
		);
	}

	/**
	 * Turns a getUpdates failure into something worth reading.
	 *
	 * @since 1.0
	 *
	 * @param WP_Error $error The error returned by the API.
	 *
	 * @return string
	 */
	public function describe_updates_error( $error ) {

		$this->log_error( __METHOD__ . sprintf( '(): Unable to get updates; code: %s; message: %s', $error->get_error_code(), $error->get_error_message() ) );

		if ( 409 !== (int) $error->get_error_code() ) {
			return $error->get_error_message();
		}

		// Telegram delivers updates through a webhook or through getUpdates, never both. Another
		// plugin using this same bot is the usual reason discovery cannot run.
		$message = esc_html__( 'This bot has a webhook registered, so Telegram will not hand over its recent messages. Another plugin on this site is most likely using the same bot. Either give this site its own bot, or enter the chat ID by hand.', 'gravity-forms-telegram-notifications' );

		$webhook = $this->api->get_webhook_info();

		if ( ! is_wp_error( $webhook ) && ! rgblank( rgar( $webhook, 'url' ) ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: The URL currently registered as the bot's webhook. */
				esc_html__( 'The webhook currently points at %s.', 'gravity-forms-telegram-notifications' ),
				'<code>' . esc_html( rgar( $webhook, 'url' ) ) . '</code>'
			);
		}

		return $message;
	}

	/**
	 * Explains a send failure when the recipient itself is the likely cause.
	 *
	 * Telegram resolves an @username to a chat only for public channels and supergroups. A person's
	 * @username never resolves, however public their account is, and the request comes back as
	 * "chat not found" — which reads as though the account does not exist.
	 *
	 * @since 1.0
	 *
	 * @param string   $chat_id The recipient the message was addressed to.
	 * @param WP_Error $error   The error Telegram returned.
	 *
	 * @return string
	 */
	public function describe_send_error( $chat_id, $error ) {

		$message = (string) $error->get_error_message();

		// Telegram writes its descriptions in English whatever the site language, so the wire text
		// is what gets matched here, not a translated string.
		$not_found = false !== stripos( $message, 'chat not found' );

		if ( ! $not_found || 0 !== strpos( trim( (string) $chat_id ), '@' ) ) {
			return $message;
		}

		return $message . ' ' . esc_html__( 'An @username only reaches a public channel or group. To reach a person, use their numeric chat ID: ask them to message the bot, then use Find my chats.', 'gravity-forms-telegram-notifications' );
	}

	/**
	 * Sends a test message to the configured recipients.
	 *
	 * @since 1.0
	 */
	public function ajax_send_test() {

		$this->verify_ajax_request();

		if ( ! $this->initialize_api() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Save a valid bot token first.', 'gravity-forms-telegram-notifications' ) ) );
		}

		$chat_ids = $this->get_default_chat_ids();

		if ( empty( $chat_ids ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Add at least one default recipient, and save, before sending a test.', 'gravity-forms-telegram-notifications' ) ) );
		}

		$text = sprintf(
			/* translators: %s: The site name. */
			esc_html__( '%s is connected. Gravity Forms can now send notifications to this chat.', 'gravity-forms-telegram-notifications' ),
			'<b>' . esc_html( get_bloginfo( 'name' ) ) . '</b>'
		);

		$sent   = array();
		$errors = array();

		foreach ( $chat_ids as $chat_id ) {

			$result = $this->api->send_message(
				array(
					'chat_id'    => $chat_id,
					'text'       => $text,
					'parse_mode' => GF_Telegram_Formatter::PARSE_MODE_HTML,
				)
			);

			if ( is_wp_error( $result ) ) {
				$this->log_error( __METHOD__ . sprintf( '(): Test message to %s failed; %s', $chat_id, $result->get_error_message() ) );
				$errors[] = sprintf( '%s (%s)', $chat_id, $this->describe_send_error( $chat_id, $result ) );
				continue;
			}

			$sent[] = $chat_id;
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: A list of chat IDs and why each failed. */
						esc_html__( 'Could not send to: %s', 'gravity-forms-telegram-notifications' ),
						esc_html( implode( ', ', $errors ) )
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: A list of chat IDs. */
					esc_html__( 'Test message sent to: %s', 'gravity-forms-telegram-notifications' ),
					esc_html( implode( ', ', $sent ) )
				),
			)
		);
	}

	/**
	 * Validates the API Base URL setting.
	 *
	 * @since 1.0
	 *
	 * @param array  $field         The field properties.
	 * @param string $field_setting The field value.
	 */
	public function validate_api_base_url( $field, $field_setting ) {

		// Empty is valid; the default is used.
		if ( rgblank( $field_setting ) ) {
			return;
		}

		$parts  = wp_parse_url( trim( $field_setting ) );
		$scheme = strtolower( (string) rgar( $parts, 'scheme' ) );

		if ( rgblank( rgar( $parts, 'host' ) ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$this->set_field_error( $field, esc_html__( 'Enter a full URL including the scheme, for example https://api.telegram.org.', 'gravity-forms-telegram-notifications' ) );
		}
	}


	// # FEED SETTINGS -------------------------------------------------------------------------------------------------

	/**
	 * Configures the settings which should be rendered on the feed edit page.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function feed_settings_fields() {

		$sections = array(
			array(
				'title'  => esc_html__( 'Telegram Feed Settings', 'gravity-forms-telegram-notifications' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => esc_html__( 'Name', 'gravity-forms-telegram-notifications' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
						'tooltip'  => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Name', 'gravity-forms-telegram-notifications' ),
							esc_html__( 'Enter a feed name to uniquely identify this setup.', 'gravity-forms-telegram-notifications' )
						),
					),
					array(
						'name'        => 'chatId',
						'label'       => esc_html__( 'Send To', 'gravity-forms-telegram-notifications' ),
						'type'        => 'select_custom',
						'choices'     => $this->get_chat_id_choices(),
						'input_class' => 'merge-tag-support mt-position-right',
						'tooltip'     => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Send To', 'gravity-forms-telegram-notifications' ),
							esc_html__( 'The chat, group or channel this feed posts to. Choose Custom to enter a chat ID directly, which accepts merge tags so the recipient can come from the submission itself.', 'gravity-forms-telegram-notifications' )
						),
					),
					array(
						'name'     => 'message',
						'label'    => esc_html__( 'Message', 'gravity-forms-telegram-notifications' ),
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => true,
						'tooltip'  => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Message', 'gravity-forms-telegram-notifications' ),
							sprintf(
								/* translators: 1: The {all_fields} merge tag. 2: The message length limit. */
								esc_html__( 'The message to send. Insert submitted values with merge tags; %1$s expands to the whole submission. Messages longer than %2$d characters are split into several.', 'gravity-forms-telegram-notifications' ),
								'{all_fields}',
								GF_Telegram_API::MAX_MESSAGE_LENGTH
							)
						),
					),
					array(
						'name'          => 'parseMode',
						'label'         => esc_html__( 'Formatting', 'gravity-forms-telegram-notifications' ),
						'type'          => 'select',
						'default_value' => GF_Telegram_Formatter::PARSE_MODE_HTML,
						'choices'       => GF_Telegram_Formatter::get_parse_mode_choices(),
						'tooltip'       => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Formatting', 'gravity-forms-telegram-notifications' ),
							esc_html__( 'How Telegram should read the message you wrote above. With HTML you can use tags such as <b> and <a href="">; with MarkdownV2 you can use *bold* and _italic_. Submitted values are always escaped, so a form field containing formatting characters is shown as typed rather than breaking the message.', 'gravity-forms-telegram-notifications' )
						),
					),
					array(
						'name'        => 'buttons',
						'label'       => esc_html__( 'Buttons', 'gravity-forms-telegram-notifications' ),
						'type'        => 'textarea',
						'class'       => 'medium merge-tag-support mt-position-right',
						'rows'        => 3,
						'description' => esc_html__( 'Optional. One button per line, written as Label | https://example.com', 'gravity-forms-telegram-notifications' ),
						'tooltip'     => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Buttons', 'gravity-forms-telegram-notifications' ),
							esc_html__( 'Buttons appear under the message. Put the label and the link on one line separated by a vertical bar; merge tags work in both halves. Links must be absolute and start with http, https or tg. A line without a valid link is skipped and noted in the log rather than sent, because Telegram rejects the whole message over one bad button.', 'gravity-forms-telegram-notifications' )
						),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Delivery Options', 'gravity-forms-telegram-notifications' ),
				'fields' => array(
					array(
						'name'    => 'messageOptions',
						'label'   => esc_html__( 'Options', 'gravity-forms-telegram-notifications' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'    => 'disableWebPagePreview',
								'label'   => esc_html__( 'Hide link previews', 'gravity-forms-telegram-notifications' ),
								'tooltip' => sprintf(
									'<h6>%s</h6>%s',
									esc_html__( 'Hide link previews', 'gravity-forms-telegram-notifications' ),
									esc_html__( 'Stops Telegram expanding the first link in the message into a preview card.', 'gravity-forms-telegram-notifications' )
								),
							),
							array(
								'name'    => 'disableNotification',
								'label'   => esc_html__( 'Send silently', 'gravity-forms-telegram-notifications' ),
								'tooltip' => sprintf(
									'<h6>%s</h6>%s',
									esc_html__( 'Send silently', 'gravity-forms-telegram-notifications' ),
									esc_html__( 'The message arrives without a notification sound.', 'gravity-forms-telegram-notifications' )
								),
							),
							array(
								'name'    => 'protectContent',
								'label'   => esc_html__( 'Prevent forwarding and saving', 'gravity-forms-telegram-notifications' ),
								'tooltip' => sprintf(
									'<h6>%s</h6>%s',
									esc_html__( 'Prevent forwarding and saving', 'gravity-forms-telegram-notifications' ),
									esc_html__( 'Recipients cannot forward or save the message. Useful when submissions contain personal data.', 'gravity-forms-telegram-notifications' )
								),
							),
						),
					),
					array(
						'name'        => 'messageThreadId',
						'label'       => esc_html__( 'Topic ID', 'gravity-forms-telegram-notifications' ),
						'type'        => 'text',
						'class'       => 'small',
						'description' => esc_html__( 'Optional. Posts into a specific topic of a forum group.', 'gravity-forms-telegram-notifications' ),
					),
					array(
						'name'           => 'feed_condition',
						'label'          => esc_html__( 'Conditional Logic', 'gravity-forms-telegram-notifications' ),
						'type'           => 'feed_condition',
						'checkbox_label' => esc_html__( 'Enable', 'gravity-forms-telegram-notifications' ),
						'instructions'   => esc_html__( 'Send the notification if', 'gravity-forms-telegram-notifications' ),
						'tooltip'        => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Conditional Logic', 'gravity-forms-telegram-notifications' ),
							esc_html__( 'When conditional logic is enabled, the notification is only sent when the condition is met. When disabled, every submission sends one.', 'gravity-forms-telegram-notifications' )
						),
					),
				),
			),
		);

		$attachment_section = $this->get_attachment_settings_section();

		if ( ! empty( $attachment_section ) ) {
			// Sits before the delivery options so the message and its files stay together.
			array_splice( $sections, 1, 0, array( $attachment_section ) );
		}

		return $sections;
	}

	/**
	 * Returns the Attachments section, or an empty array when the form has no file upload fields.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_attachment_settings_section() {

		$fields = $this->get_file_upload_fields( $this->get_current_form() );

		if ( empty( $fields ) ) {
			return array();
		}

		$choices = array();

		foreach ( $fields as $field ) {
			$choices[] = array(
				'name'  => 'attachField_' . $field->id,
				'label' => esc_html( GFCommon::get_label( $field ) ),
			);
		}

		return array(
			'title'       => esc_html__( 'Attachments', 'gravity-forms-telegram-notifications' ),
			'description' => esc_html__( 'Uploaded files are sent as separate messages, after the notification itself.', 'gravity-forms-telegram-notifications' ),
			'fields'      => array(
				array(
					'name'    => 'attachmentFields',
					'label'   => esc_html__( 'Send Files From', 'gravity-forms-telegram-notifications' ),
					'type'    => 'checkbox',
					'choices' => $choices,
					'tooltip' => sprintf(
						'<h6>%s</h6>%s',
						esc_html__( 'Send Files From', 'gravity-forms-telegram-notifications' ),
						sprintf(
							/* translators: 1: The document size limit in MB. 2: The photo size limit in MB. */
							esc_html__( 'Files uploaded to the chosen fields are attached to the notification. Telegram accepts up to %1$d MB per file, or %2$d MB when sending as a photo; anything larger is skipped and reported on the entry.', 'gravity-forms-telegram-notifications' ),
							(int) ( GF_Telegram_API::MAX_DOCUMENT_BYTES / 1048576 ),
							(int) ( GF_Telegram_API::MAX_PHOTO_BYTES / 1048576 )
						)
					),
				),
				array(
					'name'    => 'photoOptions',
					'label'   => esc_html__( 'Images', 'gravity-forms-telegram-notifications' ),
					'type'    => 'checkbox',
					'choices' => array(
						array(
							'name'    => 'sendImagesAsPhotos',
							'label'   => esc_html__( 'Show images inline instead of as files', 'gravity-forms-telegram-notifications' ),
							'tooltip' => sprintf(
								'<h6>%s</h6>%s',
								esc_html__( 'Show images inline', 'gravity-forms-telegram-notifications' ),
								esc_html__( 'Sends JPEG, PNG, GIF and WebP uploads as photos, so they preview in the chat. Telegram recompresses photos, so the original file is not preserved; leave this off when the exact file matters.', 'gravity-forms-telegram-notifications' )
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Returns the choices for the Send To setting.
	 *
	 * Milestone 7 adds chats discovered via getUpdates to this list.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_chat_id_choices() {

		$defaults = $this->get_default_chat_ids();

		$choices = array(
			array(
				'value' => '',
				'label' => empty( $defaults )
					? esc_html__( 'Default recipients (none configured yet)', 'gravity-forms-telegram-notifications' )
					: esc_html__( 'Default recipients', 'gravity-forms-telegram-notifications' ),
			),
		);

		$listed = array();

		// Chats discovered on the settings page come first, since they carry a readable name.
		foreach ( GF_Telegram_Chats::get_all() as $chat ) {

			$chat_id = (string) rgar( $chat, 'id' );

			if ( '' === $chat_id ) {
				continue;
			}

			$listed[] = $chat_id;

			$choices[] = array(
				'value' => $chat_id,
				'label' => GF_Telegram_Chats::describe( $chat ),
			);
		}

		foreach ( $defaults as $chat_id ) {

			if ( in_array( (string) $chat_id, $listed, true ) ) {
				continue;
			}

			$choices[] = array(
				'value' => $chat_id,
				'label' => $chat_id,
			);
		}

		$choices[] = array(
			'value' => 'gf_custom',
			'label' => esc_html__( 'Enter a chat ID', 'gravity-forms-telegram-notifications' ),
		);

		return $choices;
	}


	// # FEED LIST -----------------------------------------------------------------------------------------------------

	/**
	 * Sets up the columns for the feed list table.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function feed_list_columns() {

		return array(
			'feedName' => esc_html__( 'Name', 'gravity-forms-telegram-notifications' ),
			'chatId'   => esc_html__( 'Send To', 'gravity-forms-telegram-notifications' ),
		);
	}

	/**
	 * Returns the value to be displayed in the Send To column.
	 *
	 * @since 1.0
	 *
	 * @param array $feed The feed object.
	 *
	 * @return string
	 */
	public function get_column_value_chatId( $feed ) {

		$chat_id = rgars( $feed, 'meta/chatId' );

		if ( 'gf_custom' === $chat_id ) {
			return esc_html( rgars( $feed, 'meta/chatId_custom' ) );
		}

		if ( rgblank( $chat_id ) ) {
			return esc_html__( 'Default recipients', 'gravity-forms-telegram-notifications' );
		}

		return esc_html( $chat_id );
	}


	// # HELPER METHODS ------------------------------------------------------------------------------------------------

	/**
	 * Initializes the API client if the bot token is valid.
	 *
	 * Returns null when no token has been entered yet, so the settings page shows no feedback icon
	 * rather than an error.
	 *
	 * @since 1.0
	 *
	 * @return bool|null
	 */
	public function initialize_api() {

		if ( ! is_null( $this->api ) ) {
			return is_object( $this->api );
		}

		$token = $this->get_bot_token();

		if ( rgblank( $token ) ) {
			$this->api = false;

			return null;
		}

		$this->log_debug( __METHOD__ . '(): Validating bot token.' );

		$api    = new GF_Telegram_API( $token, $this->get_api_base_url() );
		$result = $api->get_me();

		if ( is_wp_error( $result ) ) {
			$this->log_error( __METHOD__ . sprintf( '(): Bot token is invalid; code: %s; message: %s', $result->get_error_code(), $result->get_error_message() ) );
			$this->api = false;

			return false;
		}

		$this->log_debug( __METHOD__ . sprintf( '(): Bot token is valid; connected as @%s.', rgar( $result, 'username' ) ) );

		$this->bot = (array) $result;
		$this->api = $api;

		return true;
	}

	/**
	 * Returns the bot token, preferring the constant over the stored setting.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_bot_token() {

		if ( $this->is_token_defined_by_constant() ) {
			return (string) GF_TELEGRAM_BOT_TOKEN;
		}

		return (string) $this->get_plugin_setting( 'botToken' );
	}

	/**
	 * Determines if the bot token is supplied by a constant rather than the settings page.
	 *
	 * @since 1.0
	 *
	 * @return bool
	 */
	public function is_token_defined_by_constant() {

		return defined( 'GF_TELEGRAM_BOT_TOKEN' ) && ! rgblank( GF_TELEGRAM_BOT_TOKEN );
	}

	/**
	 * Returns the API base URL.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_api_base_url() {

		$url = trim( (string) $this->get_plugin_setting( 'apiBaseUrl' ) );

		if ( rgblank( $url ) ) {
			$url = GF_Telegram_API::DEFAULT_BASE_URL;
		}

		/**
		 * Filters the Telegram Bot API base URL.
		 *
		 * Useful for routing requests through a proxy, or for pointing the add-on at a stub
		 * server during testing.
		 *
		 * @since 1.0
		 *
		 * @param string $url The API base URL.
		 */
		$url = apply_filters( 'gform_telegram_api_base_url', $url );

		return untrailingslashit( esc_url_raw( $url ) );
	}

	/**
	 * Returns the getMe payload for the connected bot.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_bot_info() {

		$this->initialize_api();

		return $this->bot;
	}

	/**
	 * Returns the recipients configured on the add-on settings page.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_default_chat_ids() {

		return $this->parse_chat_ids( $this->get_plugin_setting( 'defaultChatIds' ) );
	}

	/**
	 * Splits a list of chat IDs into an array.
	 *
	 * Accepts one per line, which is how the settings page asks for them, but tolerates commas so
	 * a merge tag resolving to a comma separated list also works.
	 *
	 * @since 1.0
	 *
	 * @param string $chat_ids The raw list of chat IDs.
	 *
	 * @return array
	 */
	public function parse_chat_ids( $chat_ids ) {

		if ( rgblank( $chat_ids ) ) {
			return array();
		}

		$chat_ids = preg_split( '/[\r\n,]+/', (string) $chat_ids );
		$chat_ids = array_map( 'trim', (array) $chat_ids );
		$chat_ids = array_filter( $chat_ids, function ( $chat_id ) {
			return ! rgblank( $chat_id );
		} );

		return array_values( array_unique( $chat_ids ) );
	}

	/**
	 * Returns the chat IDs this feed should post to.
	 *
	 * @since 1.0
	 *
	 * @param array $feed  The feed object.
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 *
	 * @return array
	 */
	public function get_chat_ids( $feed, $entry, $form ) {

		$chat_id = rgars( $feed, 'meta/chatId' );

		// Nothing chosen: fall back to the recipients from the add-on settings.
		if ( rgblank( $chat_id ) ) {
			return $this->get_default_chat_ids();
		}

		if ( 'gf_custom' !== $chat_id ) {
			return $this->parse_chat_ids( $chat_id );
		}

		$custom = GFCommon::replace_variables( rgars( $feed, 'meta/chatId_custom' ), $form, $entry, false, false, false, 'text' );

		return $this->parse_chat_ids( $custom );
	}

	/**
	 * Builds the message text for this submission.
	 *
	 * @since 1.0
	 *
	 * @param array $feed  The feed object.
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 *
	 * @return string
	 */
	public function get_message_text( $feed, $entry, $form ) {

		return GF_Telegram_Formatter::render(
			rgars( $feed, 'meta/message' ),
			$form,
			$entry,
			$this->get_parse_mode( $feed )
		);
	}

	/**
	 * Returns the file upload fields on a form.
	 *
	 * @since 1.0
	 *
	 * @param array $form The form object.
	 *
	 * @return array
	 */
	public function get_file_upload_fields( $form ) {

		if ( empty( $form ) ) {
			return array();
		}

		return GFAPI::get_fields_by_type( $form, array( 'fileupload' ) );
	}

	/**
	 * Returns the files this feed should attach, as absolute paths.
	 *
	 * @since 1.0
	 *
	 * @param array $feed  The feed object.
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 *
	 * @return array
	 */
	public function get_attachment_files( $feed, $entry, $form ) {

		$paths = array();

		foreach ( $this->get_file_upload_fields( $form ) as $field ) {

			if ( ! rgars( $feed, 'meta/attachField_' . $field->id ) ) {
				continue;
			}

			$value = rgar( $entry, (string) $field->id );

			if ( rgblank( $value ) ) {
				continue;
			}

			foreach ( $this->get_field_file_urls( $value ) as $url ) {

				$path = $this->get_physical_file_path( $url );

				if ( '' === $path ) {
					$this->log_error( __METHOD__ . sprintf( '(): Skipping attachment; no readable file behind %s', $url ) );
					continue;
				}

				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Returns the URLs held in a file upload field value.
	 *
	 * A single file field stores one URL; a multi-file field stores a JSON array of them.
	 *
	 * @since 1.0
	 *
	 * @param string $value The entry value for the field.
	 *
	 * @return array
	 */
	public function get_field_file_urls( $value ) {

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return array();
		}

		if ( 0 === strpos( $value, '[' ) ) {
			$urls = json_decode( $value, true );

			return is_array( $urls ) ? array_filter( $urls, 'is_string' ) : array();
		}

		return array( $value );
	}

	/**
	 * Resolves an uploaded file's URL to a path on disk.
	 *
	 * @since 1.0
	 *
	 * @param string $url The file URL stored in the entry.
	 *
	 * @return string The absolute path, or an empty string when the file cannot be found.
	 */
	public function get_physical_file_path( $url ) {

		if ( method_exists( 'GFFormsModel', 'get_physical_file_path' ) ) {

			$path = GFFormsModel::get_physical_file_path( $url );

			if ( ! empty( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		// Fall back to mapping the uploads URL onto the uploads directory.
		$uploads = wp_upload_dir();
		$baseurl = (string) rgar( $uploads, 'baseurl' );

		if ( '' !== $baseurl && 0 === strpos( $url, $baseurl ) ) {

			$path = rgar( $uploads, 'basedir' ) . substr( $url, strlen( $baseurl ) );

			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Sends one uploaded file to a chat.
	 *
	 * @since 1.0
	 *
	 * @param string $chat_id The chat to send to.
	 * @param string $path    Absolute path to the file.
	 * @param array  $args    The message arguments the file should inherit.
	 * @param array  $feed    The feed object.
	 *
	 * @return array|WP_Error
	 */
	public function send_attachment( $chat_id, $path, $args, $feed ) {

		// Guarded rather than suppressed: a file which vanished between being resolved and being
		// sent reports as zero bytes here and fails with a clear error from the API client.
		$size = is_readable( $path ) ? (int) filesize( $path ) : 0;

		$is_image = in_array(
			strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
			array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
			true
		);

		// An image too large to send as a photo is still worth sending as a file.
		$as_photo = $is_image
			&& (bool) rgars( $feed, 'meta/sendImagesAsPhotos' )
			&& $size <= GF_Telegram_API::MAX_PHOTO_BYTES;

		if ( ! $as_photo && $size > GF_Telegram_API::MAX_DOCUMENT_BYTES ) {

			$this->log_error( __METHOD__ . sprintf( '(): Skipping %s; %d bytes is over the Telegram upload limit.', basename( $path ), $size ) );

			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: 1: The file name. 2: The size limit in MB. */
					esc_html__( '%1$s is larger than the %2$d MB Telegram allows', 'gravity-forms-telegram-notifications' ),
					basename( $path ),
					(int) ( GF_Telegram_API::MAX_DOCUMENT_BYTES / 1048576 )
				)
			);
		}

		// Files inherit how the message behaves, but carry no text of their own.
		$file_args = array(
			'chat_id'              => $chat_id,
			'disable_notification' => rgar( $args, 'disable_notification' ),
			'protect_content'      => rgar( $args, 'protect_content' ),
			'message_thread_id'    => rgar( $args, 'message_thread_id' ),
		);

		$this->log_debug( __METHOD__ . sprintf( '(): Sending %s to chat %s as %s.', basename( $path ), $chat_id, $as_photo ? 'a photo' : 'a document' ) );

		return $as_photo
			? $this->api->send_photo( $file_args, $path )
			: $this->api->send_document( $file_args, $path );
	}

	/**
	 * Builds the inline keyboard for a feed.
	 *
	 * Each line of the setting is one button, written as "Label | URL". Both halves accept merge
	 * tags. Lines which cannot produce a usable button are dropped and logged: Telegram rejects
	 * the entire message when a single button is malformed, so one typo would otherwise mean no
	 * notification at all.
	 *
	 * @since 1.0
	 *
	 * @param array $feed  The feed object.
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 *
	 * @return array|null The reply_markup value, or null when the feed has no usable buttons.
	 */
	public function get_inline_keyboard( $feed, $entry, $form ) {

		$configured = rgars( $feed, 'meta/buttons' );

		if ( rgblank( $configured ) ) {
			return null;
		}

		$rows = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $configured ) as $line ) {

			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( false === strpos( $line, '|' ) ) {
				$this->log_error( __METHOD__ . sprintf( '(): Skipping button; no separator in: %s', $line ) );
				continue;
			}

			// Split on the first separator only: a query string may legitimately contain one.
			list( $label, $url ) = explode( '|', $line, 2 );

			$label = GFCommon::replace_variables( trim( $label ), $form, $entry, false, false, false, 'text' );
			$url   = GFCommon::replace_variables( trim( $url ), $form, $entry, false, false, false, 'text' );

			// Button labels are not parsed for formatting, but they do have to be a single line.
			$label = trim( preg_replace( '/\s+/u', ' ', (string) $label ) );

			if ( '' === $label ) {
				$this->log_error( __METHOD__ . sprintf( '(): Skipping button; the label is empty in: %s', $line ) );
				continue;
			}

			$url = GF_Telegram_Formatter::sanitize_button_url( $url );

			if ( '' === $url ) {
				$this->log_error( __METHOD__ . sprintf( '(): Skipping button "%s"; the link is missing or not an absolute http, https or tg URL.', $label ) );
				continue;
			}

			$rows[] = array(
				array(
					'text' => $label,
					'url'  => $url,
				),
			);
		}

		if ( empty( $rows ) ) {
			return null;
		}

		$this->log_debug( __METHOD__ . sprintf( '(): Built %d button(s).', count( $rows ) ) );

		return array( 'inline_keyboard' => $rows );
	}

	/**
	 * Returns the parse mode for a feed.
	 *
	 * @since 1.0
	 *
	 * @param array $feed The feed object.
	 *
	 * @return string
	 */
	public function get_parse_mode( $feed ) {

		$meta = rgar( $feed, 'meta', array() );

		// A feed saved before this setting existed has no value at all. Falling back to the same
		// default the settings page shows keeps the stored feed and the UI telling the same story.
		$parse_mode = array_key_exists( 'parseMode', $meta )
			? $meta['parseMode']
			: GF_Telegram_Formatter::PARSE_MODE_HTML;

		return GF_Telegram_Formatter::sanitize_parse_mode( $parse_mode );
	}


	// # FEED PROCESSING -----------------------------------------------------------------------------------------------

	/**
	 * Prevents feeds being created before the bot token has been validated.
	 *
	 * @since 1.0
	 *
	 * @return bool
	 */
	public function can_create_feed() {

		return (bool) $this->initialize_api();
	}

	/**
	 * Allows feeds to be duplicated.
	 *
	 * @since 1.0
	 *
	 * @param array|int $id The ID of the feed to be duplicated, or the feed object when duplicating a form.
	 *
	 * @return bool
	 */
	public function can_duplicate_feed( $id ) {

		return true;
	}

	/**
	 * Sends the notification.
	 *
	 * @since 1.0
	 *
	 * @param array $feed  The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form  The form object currently being processed.
	 *
	 * @return array|WP_Error
	 */
	public function process_feed( $feed, $entry, $form ) {

		if ( ! $this->initialize_api() ) {
			$this->add_feed_error( esc_html__( 'Unable to send the notification: the bot token is missing or invalid.', 'gravity-forms-telegram-notifications' ), $feed, $entry, $form );

			return new WP_Error( 'api_not_initialized', 'API not initialized.' );
		}

		$chat_ids = $this->get_chat_ids( $feed, $entry, $form );

		if ( empty( $chat_ids ) ) {
			$this->add_feed_error( esc_html__( 'Unable to send the notification: no recipient is configured.', 'gravity-forms-telegram-notifications' ), $feed, $entry, $form );

			return new WP_Error( 'no_chat_id', 'No chat ID configured.' );
		}

		$text = $this->get_message_text( $feed, $entry, $form );

		if ( rgblank( $text ) ) {
			$this->add_feed_error( esc_html__( 'Unable to send the notification: the message is empty once merge tags are replaced.', 'gravity-forms-telegram-notifications' ), $feed, $entry, $form );

			return new WP_Error( 'empty_message', 'Message is empty.' );
		}

		$thread_id  = rgars( $feed, 'meta/messageThreadId' );
		$parse_mode = $this->get_parse_mode( $feed );

		$args = array(
			'text'         => $text,
			'parse_mode'   => GF_Telegram_Formatter::PARSE_MODE_NONE === $parse_mode ? null : $parse_mode,
			'reply_markup' => $this->get_inline_keyboard( $feed, $entry, $form ),
			// The modern equivalent is link_preview_options, but the original parameter is still
			// honored and also works with older self-hosted Bot API servers.
			'disable_web_page_preview' => (bool) rgars( $feed, 'meta/disableWebPagePreview' ),
			'disable_notification'     => (bool) rgars( $feed, 'meta/disableNotification' ),
			'protect_content'          => (bool) rgars( $feed, 'meta/protectContent' ),
			'message_thread_id'        => rgblank( $thread_id ) ? null : (int) $thread_id,
		);

		/**
		 * Modifies the message arguments before they are sent to Telegram.
		 *
		 * The chat ID is not included: the arguments are built once and reused for every
		 * recipient of the feed.
		 *
		 * @since 1.0
		 *
		 * @param array $args  The sendMessage arguments.
		 * @param array $feed  The feed object.
		 * @param array $entry The entry object.
		 * @param array $form  The form object.
		 */
		$args = gf_apply_filters( array( 'gform_telegram_message_args', $form['id'], $feed['id'] ), $args, $feed, $entry, $form );

		// Split after filtering so a filter which rewrites the text is still held to the limit.
		$chunks = GF_Telegram_Formatter::split( rgar( $args, 'text' ), $parse_mode );

		if ( empty( $chunks ) ) {
			$this->add_feed_error( esc_html__( 'Unable to send the notification: the message is empty.', 'gravity-forms-telegram-notifications' ), $feed, $entry, $form );

			return new WP_Error( 'empty_message', 'Message is empty.' );
		}

		if ( count( $chunks ) > 1 ) {
			$this->log_debug( __METHOD__ . sprintf( '(): Message exceeds the Telegram length limit; sending as %d messages.', count( $chunks ) ) );
		}

		$attachments = $this->get_attachment_files( $feed, $entry, $form );

		$sent   = array();
		$errors = array();

		foreach ( $chat_ids as $chat_id ) {

			$this->log_debug( __METHOD__ . sprintf( '(): Sending message to chat %s.', $chat_id ) );

			$message_ids = array();
			$failure     = null;
			$last_chunk  = count( $chunks ) - 1;

			foreach ( $chunks as $index => $chunk ) {

				$message_args = array_merge( $args, array( 'chat_id' => $chat_id, 'text' => $chunk ) );

				// Buttons belong under the final message: on a split notification that is where
				// the reader ends up, and repeating the keyboard on every part would be noise.
				if ( $index !== $last_chunk ) {
					$message_args['reply_markup'] = null;
				}

				$result = $this->api->send_message( $message_args );

				if ( is_wp_error( $result ) ) {
					$failure = $result;
					break;
				}

				$message_ids[] = '#' . rgar( $result, 'message_id' );
			}

			// Files follow the text so the notification reads first and the uploads come after.
			if ( is_null( $failure ) ) {

				foreach ( $attachments as $path ) {

					$result = $this->send_attachment( $chat_id, $path, $args, $feed );

					if ( is_wp_error( $result ) ) {
						$failure = $result;
						break;
					}

					$message_ids[] = '#' . rgar( $result, 'message_id' );
				}
			}

			if ( ! is_null( $failure ) ) {
				$this->log_error( __METHOD__ . sprintf( '(): Unable to send message to chat %s; code: %s; message: %s', $chat_id, $failure->get_error_code(), $failure->get_error_message() ) );

				$errors[] = sprintf(
					/* translators: 1: The chat ID. 2: The error message returned by Telegram. */
					esc_html__( '%1$s (%2$s)', 'gravity-forms-telegram-notifications' ),
					$chat_id,
					$this->describe_send_error( $chat_id, $failure )
				);

				continue;
			}

			$this->log_debug( __METHOD__ . sprintf( '(): Message sent to chat %s; message IDs: %s.', $chat_id, implode( ', ', $message_ids ) ) );

			$sent[] = sprintf( '%s (%s)', $chat_id, implode( ', ', $message_ids ) );
		}

		if ( ! empty( $errors ) ) {

			// add_feed_error() both logs and adds the entry note, so successful recipients are
			// named here rather than in a second note.
			$message = sprintf(
				/* translators: %s: A comma separated list of chat IDs and the reason each failed. */
				esc_html__( 'Unable to send the Telegram notification to: %s.', 'gravity-forms-telegram-notifications' ),
				implode( ', ', $errors )
			);

			if ( ! empty( $sent ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: A comma separated list of chat IDs which did receive the message. */
					esc_html__( 'It was delivered to: %s.', 'gravity-forms-telegram-notifications' ),
					implode( ', ', $sent )
				);
			}

			$this->add_feed_error( $message, $feed, $entry, $form );

			return new WP_Error( 'send_failed', $message );
		}

		$this->add_note(
			rgar( $entry, 'id' ),
			sprintf(
				/* translators: %s: A comma separated list of chat IDs and the resulting message IDs. */
				esc_html__( 'Telegram notification sent to: %s.', 'gravity-forms-telegram-notifications' ),
				implode( ', ', $sent )
			),
			'success'
		);

		return $entry;
	}
}

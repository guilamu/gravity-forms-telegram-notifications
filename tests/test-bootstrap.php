<?php
/**
 * Checks that the plugin actually loads the classes it uses.
 *
 * The other suites require each class file by hand, so they cannot see a class that production
 * never loads. This suite reads the require statements out of the main plugin file and compares
 * them against every class the plugin's own code references.
 */

require __DIR__ . '/bootstrap.php';

$plugin_dir  = GF_TELEGRAM_PLUGIN_DIR;
$main_file   = $plugin_dir . '/gravity-forms-telegram-notifications.php';
$main_source = file_get_contents( $main_file );

echo "\n--- Loaded files ---\n";

// Every `require_once GF_TELEGRAM_PATH . '<path>';` in the main file, at any nesting level.
preg_match_all( "/require_once\s+GF_TELEGRAM_PATH\s*\.\s*'([^']+)'/", $main_source, $matches );
$required = $matches[1];

check( 'the main plugin file requires class files', ! empty( $required ) );

/**
 * Returns the names of the classes a PHP file declares.
 *
 * @param string $path Absolute path to a PHP file.
 *
 * @return array
 */
function tg_classes_declared( $path ) {

	$classes = array();
	$tokens  = token_get_all( file_get_contents( $path ) );
	$count   = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {

		if ( ! is_array( $tokens[ $i ] ) || T_CLASS !== $tokens[ $i ][0] ) {
			continue;
		}

		// Skip past whitespace to the name. `Foo::class` also starts with T_CLASS but is preceded
		// by T_DOUBLE_COLON, so it is filtered out by the T_STRING check below.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}

		if ( $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
			$classes[] = $tokens[ $j ][1];
		}
	}

	return $classes;
}

/**
 * Returns the names of the plugin classes a PHP file references, by `new Name` or `Name::`.
 *
 * @param string $path Absolute path to a PHP file.
 *
 * @return array
 */
function tg_classes_referenced( $path ) {

	$referenced = array();
	$tokens     = token_get_all( file_get_contents( $path ) );
	$count      = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {

		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] ) {
			continue;
		}

		$name = $tokens[ $i ][1];

		if ( 0 !== strpos( $name, 'GF_Telegram_' ) && 'GFTelegram' !== $name ) {
			continue;
		}

		$previous = $i - 1;
		while ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_WHITESPACE === $tokens[ $previous ][0] ) {
			$previous--;
		}

		$next = $i + 1;
		while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
			$next++;
		}

		$is_new    = $previous >= 0 && is_array( $tokens[ $previous ] ) && T_NEW === $tokens[ $previous ][0];
		$is_static = $next < $count && is_array( $tokens[ $next ] ) && T_DOUBLE_COLON === $tokens[ $next ][0];

		if ( $is_new || $is_static ) {
			$referenced[ $name ] = $name;
		}
	}

	return array_values( $referenced );
}

$loaded = tg_classes_declared( $main_file );

foreach ( $required as $relative ) {

	$path = $plugin_dir . '/' . $relative;

	// Compared against the real directory listing: this repository is developed on Windows and
	// deployed on Linux, where a require with the wrong capitalization is a fatal error.
	$listed = (array) glob( dirname( $path ) . '/*.php' );
	check(
		"required file exists, exact case: {$relative}",
		in_array( str_replace( '\\', '/', $path ), array_map( function ( $found ) {
			return str_replace( '\\', '/', $found );
		}, $listed ), true )
	);

	if ( file_exists( $path ) ) {
		$loaded = array_merge( $loaded, tg_classes_declared( $path ) );
	}
}

echo "\n--- Referenced classes are loaded ---\n";

$plugin_files = array();
foreach ( array( '', '/includes' ) as $sub ) {
	foreach ( (array) glob( $plugin_dir . $sub . '/*.php' ) as $file ) {
		$plugin_files[] = $file;
	}
}

foreach ( $plugin_files as $file ) {

	$relative = str_replace( '\\', '/', substr( $file, strlen( $plugin_dir ) + 1 ) );

	foreach ( tg_classes_referenced( $file ) as $class ) {
		check(
			"{$relative} references {$class}, which the plugin loads",
			in_array( $class, $loaded, true ),
			'not loaded by gravity-forms-telegram-notifications.php'
		);
	}
}

echo "\n--- Load order ---\n";

// The add-on's own file comes last: it reads constants off the classes beside it while its
// settings pages render, so those have to be in memory first.
$addon_position = array_search( 'class-gf-telegram.php', $required, true );

check( 'the add-on class is required', false !== $addon_position );

foreach ( $required as $position => $relative ) {
	if ( 0 !== strpos( $relative, 'includes/class-gf-telegram-' ) ) {
		continue;
	}
	check( "{$relative} is required before the add-on class", $position < $addon_position );
}

echo "\n--- AJAX handlers ---\n";

// The framework picks one context per request: a call to admin-ajax.php runs init_ajax() and
// never init_admin(). A wp_ajax_ hook registered in the wrong one is not registered at all when
// the settings page calls it, and admin-ajax answers with a bare 0 — no error, nothing logged.
$addon_source = file_get_contents( $plugin_dir . '/class-gf-telegram.php' );

/**
 * Returns the body of a method, from its signature to the closing brace at the same depth.
 *
 * @param string $source The PHP source to search.
 * @param string $method The method name.
 *
 * @return string Empty when the method is not declared.
 */
function tg_method_body( $source, $method ) {

	$start = strpos( $source, 'function ' . $method . '(' );

	if ( false === $start ) {
		return '';
	}

	$open  = strpos( $source, '{', $start );
	$depth = 0;

	for ( $i = $open; $i < strlen( $source ); $i++ ) {
		if ( '{' === $source[ $i ] ) {
			$depth++;
		} elseif ( '}' === $source[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $source, $open, $i - $open );
			}
		}
	}

	return '';
}

preg_match_all( "/add_action\(\s*'(wp_ajax_[a-z_]+)'/", $addon_source, $matches );
$ajax_actions = $matches[1];

check( 'the add-on registers AJAX handlers', ! empty( $ajax_actions ) );

$init_ajax  = tg_method_body( $addon_source, 'init_ajax' );
$init_admin = tg_method_body( $addon_source, 'init_admin' );

foreach ( $ajax_actions as $action ) {
	check( "{$action} is registered in init_ajax()", false !== strpos( $init_ajax, $action ) );
	check( "{$action} is not registered in init_admin()", false === strpos( $init_admin, $action ) );
}

// Every action hooked has a callable method behind it, and every string the script asks for is
// actually passed to it.
foreach ( $ajax_actions as $action ) {
	preg_match( "/add_action\(\s*'" . $action . "',\s*array\(\s*\\\$this,\s*'([a-z_]+)'/", $addon_source, $callback );
	check(
		"{$action} points at a method that exists",
		! empty( $callback[1] ) && false !== strpos( $addon_source, 'function ' . $callback[1] . '(' )
	);
}

echo "\n--- Script strings ---\n";

$script_strings = array();
if ( preg_match( "/'strings'\s*=>\s*array\((.*?)\n\t+\)/s", $addon_source, $block ) ) {
	preg_match_all( "/'([a-zA-Z]+)'\s*=>/", $block[1], $keys );
	$script_strings = $keys[1];
}

check( 'the settings page script is given its strings', ! empty( $script_strings ) );

$js = file_get_contents( $plugin_dir . '/assets/admin.js' );

preg_match_all( '/strings\.([a-zA-Z]+)/', $js, $used );

foreach ( array_unique( $used[1] ) as $key ) {
	check( "admin.js reads strings.{$key}, which PHP provides", in_array( $key, $script_strings, true ) );
}

summarize();

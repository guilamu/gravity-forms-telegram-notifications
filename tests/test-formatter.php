<?php
/**
 * Exercises GF_Telegram_Formatter: escaping, the tag allowlist and message splitting.
 */

require __DIR__ . '/bootstrap.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-api.php';
require GF_TELEGRAM_PLUGIN_DIR . '/includes/class-gf-telegram-formatter.php';

$form = array( 'id' => 7 );

// The values a real submission can carry: formatting characters, markup, quotes.
$entry = array(
	'id'  => 1,
	'1'   => 'Anne_Marie *VIP*',
	'2'   => '<b>not bold</b>',
	'3'   => 'Tom & Jerry <tom@example.org>',
	'4'   => 'price: 12.50 (net) [urgent]',
	'5'   => 'she said "yes"',
	'6'   => 'plain',
);

echo "\n1. HTML mode escapes values but keeps the admin's own tags\n";
$out = GF_Telegram_Formatter::render( '<b>New entry</b> from {1}', $form, $entry, 'HTML' );
check( "admin's bold tag survives", false !== strpos( $out, '<b>New entry</b>' ), $out );
check( 'underscore in a value is untouched', false !== strpos( $out, 'Anne_Marie' ), $out );
check( 'asterisks in a value are untouched', false !== strpos( $out, '*VIP*' ), $out );

$out = GF_Telegram_Formatter::render( 'Message: {2}', $form, $entry, 'HTML' );
check( 'markup inside a value is escaped', false !== strpos( $out, '&lt;b&gt;not bold&lt;/b&gt;' ), $out );
check( 'no live tag leaked from the value', false === strpos( $out, '<b>not bold' ), $out );

$out = GF_Telegram_Formatter::render( 'Contact: {3}', $form, $entry, 'HTML' );
check( 'ampersand is escaped', false !== strpos( $out, 'Tom &amp; Jerry' ), $out );
check( 'angle brackets around an email are escaped', false !== strpos( $out, '&lt;tom@example.org&gt;' ), $out );

echo "\n2. A merge tag inside an href cannot break out of the attribute\n";
$out = GF_Telegram_Formatter::render( '<a href="https://x.test/?q={5}">link</a>', $form, $entry, 'HTML' );
check( 'quotes in the value are escaped', false === strpos( $out, 'q=she said "yes"' ), $out );
check( 'value is entity encoded', false !== strpos( $out, '&quot;yes&quot;' ), $out );

echo "\n3. Disallowed tags are stripped from the template\n";
$out = GF_Telegram_Formatter::render( '<div class="x">hi</div><script>alert(1)</script><b>ok</b>', $form, $entry, 'HTML' );
check( 'div removed', false === strpos( $out, '<div' ), $out );
check( 'script removed', false === strpos( $out, '<script' ), $out );
check( 'allowed tag kept', false !== strpos( $out, '<b>ok</b>' ), $out );

echo "\n4. MarkdownV2 escapes every reserved character in values\n";
$out = GF_Telegram_Formatter::render( 'From {1}', $form, $entry, 'MarkdownV2' );
check( 'underscore escaped', false !== strpos( $out, 'Anne\\_Marie' ), $out );
check( 'asterisk escaped', false !== strpos( $out, '\\*VIP\\*' ), $out );

$out = GF_Telegram_Formatter::render( '{4}', $form, $entry, 'MarkdownV2' );
check( 'period escaped', false !== strpos( $out, '12\\.50' ), $out );
check( 'parentheses escaped', false !== strpos( $out, '\\(net\\)' ), $out );
check( 'brackets escaped', false !== strpos( $out, '\\[urgent\\]' ), $out );

$out = GF_Telegram_Formatter::render( '*Bold* from {6}', $form, $entry, 'MarkdownV2' );
check( "admin's own asterisks are left alone", 0 === strpos( $out, '*Bold*' ), $out );

echo "\n5. Every MarkdownV2 reserved character is covered\n";
$reserved = str_split( '_*[]()~`>#+-=|{}.!' );
$missed   = array();
foreach ( $reserved as $character ) {
	$escaped = GF_Telegram_Formatter::escape( $character, 'MarkdownV2' );
	if ( '\\' . $character !== $escaped ) {
		$missed[] = $character;
	}
}
check( 'all 18 reserved characters escape', empty( $missed ), 'missed: ' . implode( ' ', $missed ) );
check( 'backslash itself escapes', '\\\\' === GF_Telegram_Formatter::escape( '\\', 'MarkdownV2' ) );

echo "\n6. Plain mode changes nothing\n";
$raw = 'Anne_Marie *VIP* <b>x</b> & co.';
check( 'value passes through untouched', $raw === GF_Telegram_Formatter::escape( $raw, '' ) );
$out = GF_Telegram_Formatter::render( 'From {1}', $form, $entry, '' );
check( 'render leaves the value alone', 'From Anne_Marie *VIP*' === $out, $out );

echo "\n7. Unknown parse modes fall back to plain text rather than guessing\n";
check( 'unknown mode sanitizes to none', '' === GF_Telegram_Formatter::sanitize_parse_mode( 'Markdown' ) );
check( 'html is recognized', 'HTML' === GF_Telegram_Formatter::sanitize_parse_mode( 'HTML' ) );
check( 'value is not escaped under an unknown mode', 'a_b' === GF_Telegram_Formatter::escape( 'a_b', 'nonsense' ) );

echo "\n8. Length is counted the way Telegram counts it\n";
check( 'ascii counts one per character', 5 === GF_Telegram_Formatter::length( 'hello' ) );
check( 'accents count one', 6 === GF_Telegram_Formatter::length( 'réunion' ) - 1 );
check( 'emoji counts as two UTF-16 units', 2 === GF_Telegram_Formatter::length( '🎉' ) );
check( 'mixed string adds up', 4 === GF_Telegram_Formatter::length( 'ab🎉' ) );

echo "\n9. Short messages are not split\n";
check( 'single chunk returned', array( 'hello' ) === GF_Telegram_Formatter::split( 'hello', '' ) );
check( 'empty text gives no chunks', array() === GF_Telegram_Formatter::split( '', '' ) );

echo "\n10. Long messages split on line boundaries\n";
$lines = array();
for ( $i = 0; $i < 300; $i++ ) {
	$lines[] = "Line {$i} " . str_repeat( 'x', 30 );
}
$text   = implode( "\n", $lines );
$chunks = GF_Telegram_Formatter::split( $text, '' );
check( 'more than one chunk', count( $chunks ) > 1, count( $chunks ) . ' chunks' );
$within = true;
foreach ( $chunks as $chunk ) {
	if ( GF_Telegram_Formatter::length( $chunk ) > GF_Telegram_API::MAX_MESSAGE_LENGTH ) {
		$within = false;
	}
}
check( 'every chunk fits', $within );
check( 'rejoining restores the text', $text === implode( "\n", $chunks ), 'lost content' );
$broken = false;
foreach ( $chunks as $chunk ) {
	if ( ! preg_match( '/^Line \d+ x/', $chunk ) ) {
		$broken = true;
	}
}
check( 'no chunk starts mid-line', ! $broken );

echo "\n11. A single over-long line is cut without losing anything\n";
$text   = str_repeat( 'a', 9000 );
$chunks = GF_Telegram_Formatter::split( $text, '' );
check( 'split into three', 3 === count( $chunks ), count( $chunks ) . ' chunks' );
check( 'nothing lost', $text === implode( '', $chunks ) );
check( 'each chunk fits', GF_Telegram_Formatter::length( $chunks[0] ) <= GF_Telegram_API::MAX_MESSAGE_LENGTH );

echo "\n12. Multi-byte characters are never cut in half\n";
$text   = str_repeat( '🎉', 3000 );
$chunks = GF_Telegram_Formatter::split( $text, '' );
$valid  = true;
foreach ( $chunks as $chunk ) {
	// A /u pattern fails to match against invalid UTF-8, which makes it a usable validity check.
	if ( 1 !== preg_match( '//u', $chunk ) || preg_match( '/\xEF\xBF\xBD/', $chunk ) ) {
		$valid = false;
	}
}
check( 'all chunks are valid UTF-8', $valid );
check( 'nothing lost', $text === implode( '', $chunks ) );
$within = true;
foreach ( $chunks as $chunk ) {
	if ( GF_Telegram_Formatter::length( $chunk ) > GF_Telegram_API::MAX_MESSAGE_LENGTH ) {
		$within = false;
	}
}
check( 'emoji counted as two, so chunks still fit', $within );

echo "\n13. HTML splitting closes and reopens tags across the boundary\n";
$lines = array( '<b>' );
for ( $i = 0; $i < 300; $i++ ) {
	$lines[] = "Line {$i} " . str_repeat( 'y', 30 );
}
$lines[] = '</b>';
$chunks  = GF_Telegram_Formatter::split( implode( "\n", $lines ), 'HTML' );
check( 'more than one chunk', count( $chunks ) > 1, count( $chunks ) . ' chunks' );
$balanced = true;
foreach ( $chunks as $chunk ) {
	if ( substr_count( $chunk, '<b>' ) !== substr_count( $chunk, '</b>' ) ) {
		$balanced = false;
	}
}
check( 'every chunk has balanced tags', $balanced, implode( ' | ', array_map( function ( $c ) {
	return substr_count( $c, '<b>' ) . '/' . substr_count( $c, '</b>' );
}, $chunks ) ) );
check( 'first chunk opens bold', false !== strpos( $chunks[0], '<b>' ) );
check( 'later chunk reopens bold', 0 === strpos( $chunks[1], '<b>' ), substr( $chunks[1], 0, 20 ) );
$within = true;
foreach ( $chunks as $chunk ) {
	if ( GF_Telegram_Formatter::length( $chunk ) > GF_Telegram_API::MAX_MESSAGE_LENGTH ) {
		$within = false;
	}
}
check( 'balancing did not push a chunk over the limit', $within );

echo "\n14. Nested tags unwind in the right order\n";
$lines = array( '<b>', '<i>' );
for ( $i = 0; $i < 300; $i++ ) {
	$lines[] = "Line {$i} " . str_repeat( 'z', 30 );
}
$chunks = GF_Telegram_Formatter::split( implode( "\n", $lines ), 'HTML' );
check( 'inner tag closes before outer', false !== strpos( $chunks[0], '</i></b>' ), substr( $chunks[0], -30 ) );
check( 'next chunk reopens both', 0 === strpos( $chunks[1], '<b><i>' ), substr( $chunks[1], 0, 20 ) );

echo "\n15. Button URLs are accepted only when Telegram would accept them\n";
check( 'https allowed', 'https://example.org/x?a=1' === GF_Telegram_Formatter::sanitize_button_url( 'https://example.org/x?a=1' ) );
check( 'http allowed', 'http://example.org' === GF_Telegram_Formatter::sanitize_button_url( 'http://example.org' ) );
check( 'tg allowed', 'tg://resolve?domain=telegram' === GF_Telegram_Formatter::sanitize_button_url( 'tg://resolve?domain=telegram' ) );
check( 'surrounding space trimmed', 'https://example.org' === GF_Telegram_Formatter::sanitize_button_url( '  https://example.org  ' ) );
check( 'javascript rejected', '' === GF_Telegram_Formatter::sanitize_button_url( 'javascript:alert(1)' ) );
check( 'data URI rejected', '' === GF_Telegram_Formatter::sanitize_button_url( 'data:text/html;base64,PHA+' ) );
check( 'relative path rejected', '' === GF_Telegram_Formatter::sanitize_button_url( '/entries/5' ) );
check( 'bare host rejected', '' === GF_Telegram_Formatter::sanitize_button_url( 'example.org' ) );
check( 'empty rejected', '' === GF_Telegram_Formatter::sanitize_button_url( '' ) );
check( 'unresolved merge tag rejected', '' === GF_Telegram_Formatter::sanitize_button_url( '{entry_url}' ) );

echo "\n16. The reference plugin's failure case\n";
// ultimate-integration-for-telegram sends this straight through and Telegram answers 400,
// silently dropping the notification.
$hostile = array( 'id' => 1, '1' => 'John_Doe', '2' => '2+2=4 (really!)' );
$out     = GF_Telegram_Formatter::render( 'New entry from {1}: {2}', $form, $hostile, 'MarkdownV2' );
check( 'nothing in the output is an unescaped reserved character', 0 === preg_match( '/(?<!\\\\)[_*\[\]()~`>#+=|{}.!-]/', $out ), $out );

summarize();

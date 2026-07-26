<?php
/**
 * Runs every suite. Starts the Telegram stub server first and shuts it down afterwards.
 *
 * Usage: php tests/run.php
 */

$port      = getenv( 'TG_STUB_PORT' ) ? (int) getenv( 'TG_STUB_PORT' ) : 8799;
$stub_file = __DIR__ . '/stub-server.php';
$suites    = array( 'test-bootstrap.php', 'test-api.php', 'test-feed.php', 'test-formatter.php', 'test-chats.php' );

// --- Stub server -------------------------------------------------------------------------------

$descriptors = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'file', __DIR__ . '/.stub.log', 'w' ),
	2 => array( 'file', __DIR__ . '/.stub.log', 'a' ),
);

$command = sprintf(
	'%s -S 127.0.0.1:%d %s',
	escapeshellarg( PHP_BINARY ),
	$port,
	escapeshellarg( $stub_file )
);

$server = proc_open( $command, $descriptors, $pipes );

if ( ! is_resource( $server ) ) {
	fwrite( STDERR, "Could not start the stub server.\n" );
	exit( 1 );
}

// Nothing writes to the server's stdin.
if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
	fclose( $pipes[0] );
}

/**
 * Stops the stub server.
 *
 * On Windows proc_open runs the command through cmd.exe, so proc_terminate() kills the wrapper and
 * leaves php.exe listening — which then holds the console pipe open and appears to hang whatever
 * started the run. Killing the whole process tree is what actually stops it.
 */
function tg_stop_server( $server ) {

	static $stopped = false;

	if ( $stopped || ! is_resource( $server ) ) {
		return;
	}

	$stopped = true;
	$status  = proc_get_status( $server );

	if ( ! empty( $status['running'] ) && ! empty( $status['pid'] ) && 0 === stripos( PHP_OS, 'WIN' ) ) {
		exec( sprintf( 'taskkill /F /T /PID %d 2>&1', (int) $status['pid'] ) );
	}

	proc_terminate( $server );
	proc_close( $server );
}

register_shutdown_function( function () use ( $server ) {
	tg_stop_server( $server );
} );

// Wait for the port to accept connections rather than guessing at a sleep duration.
$ready = false;
for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 );
	if ( $socket ) {
		fclose( $socket );
		$ready = true;
		break;
	}
	usleep( 100000 );
}

if ( ! $ready ) {
	fwrite( STDERR, "The stub server never came up on port {$port}.\n" );
	tg_stop_server( $server );
	exit( 1 );
}

echo "Stub server listening on 127.0.0.1:{$port}\n";

// --- Suites ------------------------------------------------------------------------------------

$failures = 0;

foreach ( $suites as $suite ) {

	$path = __DIR__ . '/' . $suite;

	if ( ! file_exists( $path ) ) {
		continue;
	}

	echo "\n========================================\n  {$suite}\n========================================\n";

	$exit_code = 0;
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $path ), $exit_code );

	if ( 0 !== $exit_code ) {
		$failures++;
	}
}

tg_stop_server( $server );

echo "\n========================================\n";
echo 0 === $failures ? "  All suites passed.\n" : "  {$failures} suite(s) failed.\n";

exit( $failures > 0 ? 1 : 0 );

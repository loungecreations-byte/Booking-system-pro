<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep the mu bootstrap minimal: only load active global runtime modules.
$modules = array(
	'admin-optimizer.php',
	'performance-api.php',
);

foreach ( $modules as $module ) {
	$file = __DIR__ . '/modules/' . $module;
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

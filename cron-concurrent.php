<?php

/**
 * WP-CLI Cron Concurrent – entry point.
 *
 * Loaded automatically by WP-CLI when this package is installed.
 * Registers the `wp cron-concurrent` command group.
 *
 * @package dd32/wpcli-cron-concurrent
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

WP_CLI::add_command( 'cron-concurrent', 'CronConcurrent' );

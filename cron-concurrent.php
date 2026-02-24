<?php
/**
 * Plugin Name: WP-CLI Cron Concurrent
 * Description: Runs WordPress cron tasks concurrently via wp-cli.
 * Version: 1.0.0
 * Author: dd32
 * License: MIT
 */

/**
 * WP-CLI Cron Concurrent – entry point.
 *
 * Loaded automatically by WP-CLI when this package is installed via Composer,
 * or explicitly via `--require` / a `wp-cli.yml` require entry.
 * Registers the `wp cron-concurrent` command group.
 *
 * @package dd32/wpcli-cron-concurrent
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

// When installed as a Composer package the classmap autoloader already
// handles this file; the direct require is only a safety-net for manual
// --require usage (e.g. inside wp-env test containers).
if ( ! class_exists( 'CronConcurrent' ) ) {
	require_once __DIR__ . '/src/CronConcurrent.php';
}

WP_CLI::add_command( 'cron-concurrent', 'CronConcurrent' );

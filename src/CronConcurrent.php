<?php

/**
 * WP-CLI command to run WordPress cron tasks concurrently.
 *
 * @package dd32/wpcli-cron-concurrent
 */
class CronConcurrent extends WP_CLI_Command {

	/**
	 * Run all pending cron tasks concurrently.
	 *
	 * ## OPTIONS
	 *
	 * [--filter=<filter>]
	 * : Only run hooks whose name contains this string.
	 *
	 * [--concurrent=<n>]
	 * : Maximum number of tasks to run at the same time. Default: 5.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run all pending cron tasks concurrently.
	 *     $ wp cron-concurrent run
	 *     Running 4 cron task(s) concurrently (max 5 at a time)...
	 *
	 *     # Run only tasks whose hook name contains "woocommerce".
	 *     $ wp cron-concurrent run --filter=woocommerce
	 *
	 *     # Limit to 3 concurrent tasks.
	 *     $ wp cron-concurrent run --concurrent=3
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function run( array $args, array $assoc_args ) {
		$filter     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'filter', '' );
		$concurrent = max( 1, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'concurrent', 5 ) );

		$hooks = $this->get_due_hooks( $filter );

		if ( empty( $hooks ) ) {
			WP_CLI::success( 'No pending cron tasks found.' );
			return;
		}

		WP_CLI::log(
			sprintf(
				'Running %d cron task(s) concurrently (max %d at a time)...',
				count( $hooks ),
				$concurrent
			)
		);

		$this->run_concurrently( $hooks, $concurrent );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Return the list of distinct hook names that are currently due.
	 *
	 * @param string $filter Optional substring filter applied to hook names.
	 * @return string[]
	 */
	private function get_due_hooks( string $filter ): array {
		$cron  = _get_cron_array();
		$now   = time();
		$hooks = [];

		foreach ( (array) $cron as $timestamp => $hook_map ) {
			if ( $timestamp > $now ) {
				continue;
			}
			foreach ( $hook_map as $hook => $instances ) {
				if ( $filter !== '' && strpos( $hook, $filter ) === false ) {
					continue;
				}
				foreach ( $instances as $instance ) {
					$hooks[] = [ 'hook' => $hook, 'args' => $instance['args'] ];
				}
			}
		}

		return $hooks;
	}

	/**
	 * Build the base WP-CLI shell command, reusing the current path / URL
	 * context so subprocesses connect to the same WordPress installation.
	 *
	 * @return string
	 */
	private function wp_command_base(): string {
		$php    = \WP_CLI\Utils\get_php_binary();
		$script = escapeshellarg( realpath( $GLOBALS['argv'][0] ) );
		$cmd    = "{$php} {$script}";

		$runner = WP_CLI::get_runner();
		foreach ( [ 'path', 'url', 'user', 'ssh', 'http' ] as $key ) {
			if ( ! empty( $runner->config[ $key ] ) ) {
				$cmd .= ' --' . $key . '=' . escapeshellarg( $runner->config[ $key ] );
			}
		}

		// Suppress subprocess colour so ANSI codes don't pollute the last-line
		// preview shown in our display.
		$cmd .= ' --no-color';

		return $cmd;
	}

	/**
	 * Spawn a subprocess that runs one cron event and return its handle.
	 *
	 * @param array  $event  Associative array with 'hook' and 'args' keys.
	 * @param string $wp_cmd Base WP-CLI command string.
	 * @param int    $id     Numeric ID for tracking.
	 * @return array Process handle array.
	 */
	private function start_process( array $event, string $wp_cmd, int $id ): array {
		$cmd = $wp_cmd . ' cron event run ' . escapeshellarg( $event['hook'] ) . ' 2>&1';

		$proc = proc_open(
			$cmd,
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );

		return [
			'id'         => $id,
			'hook'       => $event['hook'],
			'proc'       => $proc,
			'pipe'       => $pipes[1],
			'buffer'     => '',
			'last_line'  => '',
			'start_time' => microtime( true ),
			'end_time'   => null,
			'exit_code'  => null,
		];
	}

	/**
	 * Update the last_line field of a process handle with any newly buffered
	 * output read from its stdout pipe.
	 *
	 * @param array $handle Process handle (passed by reference).
	 */
	private function read_output( array &$handle ): void {
		$chunk = fread( $handle['pipe'], 8192 );
		if ( $chunk === false || $chunk === '' ) {
			return;
		}
		$handle['buffer'] .= $chunk;
		$lines = array_filter( array_map( 'trim', explode( "\n", $handle['buffer'] ) ) );
		if ( $lines ) {
			// Keep the last complete line; the rest stays in the buffer until
			// the next newline arrives (prevents partial-line display).
			if ( substr( $handle['buffer'], -1 ) === "\n" ) {
				$handle['last_line'] = end( $lines );
				$handle['buffer']    = '';
			} else {
				// The last element may be incomplete – only advance up to the
				// second-to-last confirmed complete line.
				$complete = array_slice( $lines, 0, -1 );
				if ( $complete ) {
					$handle['last_line'] = end( $complete );
				}
			}
		}
	}

	/**
	 * Drain any remaining output from a pipe after the process has exited and
	 * update the last_line field.
	 *
	 * @param array $handle Process handle (passed by reference).
	 */
	private function drain_pipe( array &$handle ): void {
		stream_set_blocking( $handle['pipe'], true );
		$remaining = stream_get_contents( $handle['pipe'] );
		if ( $remaining !== false && $remaining !== '' ) {
			$handle['buffer'] .= $remaining;
		}
		$lines = array_filter( array_map( 'trim', explode( "\n", $handle['buffer'] ) ) );
		if ( $lines ) {
			$handle['last_line'] = end( $lines );
		}
		$handle['buffer'] = '';
	}

	/**
	 * Run all hooks from $queue with at most $max_concurrent live processes.
	 *
	 * @param array $queue         List of ['hook'=>..., 'args'=>...] entries.
	 * @param int   $max_concurrent Maximum number of simultaneous subprocesses.
	 */
	private function run_concurrently( array $queue, int $max_concurrent ): void {
		$wp_cmd        = $this->wp_command_base();
		$running       = [];  // id => handle
		$finished      = [];  // ordered list of finished handles
		$next_id       = 1;
		$lines_printed = 0;
		$is_tty        = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

		while ( ! empty( $queue ) || ! empty( $running ) ) {
			// Spawn new processes up to the concurrency cap.
			while ( ! empty( $queue ) && count( $running ) < $max_concurrent ) {
				$event                    = array_shift( $queue );
				$handle                   = $this->start_process( $event, $wp_cmd, $next_id++ );
				$running[ $handle['id'] ] = $handle;
			}

			// Read output and detect finished processes.
			foreach ( $running as $id => $handle ) {
				$this->read_output( $running[ $id ] );

				$status = proc_get_status( $handle['proc'] );
				if ( ! $status['running'] ) {
					$this->drain_pipe( $running[ $id ] );
					$running[ $id ]['exit_code'] = $status['exitcode'];
					$running[ $id ]['end_time']  = microtime( true );
					proc_close( $running[ $id ]['proc'] );
					$finished[] = $running[ $id ];
					unset( $running[ $id ] );
				}
			}

			// Refresh the terminal display.
			$lines_printed = $this->render( $running, $finished, $lines_printed, $is_tty );

			if ( ! empty( $running ) ) {
				usleep( 100000 ); // 100 ms polling interval
			}
		}

		// Summary line.
		$failed = array_filter( $finished, static function ( $h ) { return $h['exit_code'] !== 0; } );
		echo "\n";
		if ( ! empty( $failed ) ) {
			WP_CLI::warning(
				sprintf(
					'Completed: %d succeeded, %d failed.',
					count( $finished ) - count( $failed ),
					count( $failed )
				)
			);
		} else {
			WP_CLI::success(
				sprintf( 'All %d cron task(s) completed successfully.', count( $finished ) )
			);
		}
	}

	/**
	 * Redraw the task status table, clearing the previously printed lines.
	 *
	 * Running tasks are shown before finished ones. Each row contains:
	 *   [spinner / status icon]  [hook name]  [elapsed time]  [last output line]
	 *
	 * @param array $running       Map of id => handle for in-flight processes.
	 * @param array $finished      Ordered list of completed handles.
	 * @param int   $lines_printed Number of lines output in the previous render pass.
	 * @param bool  $is_tty        Whether STDOUT is a real terminal.
	 * @return int  Number of lines printed this pass.
	 */
	private function render( array $running, array $finished, int $lines_printed, bool $is_tty ): int {
		// On a real TTY, move the cursor up to overwrite the previous render.
		if ( $is_tty && $lines_printed > 0 ) {
			echo str_repeat( "\033[A\033[2K", $lines_printed );
		}

		$lines = 0;

		foreach ( $running as $handle ) {
			$this->print_row( $handle, true );
			++$lines;
		}

		foreach ( $finished as $handle ) {
			$this->print_row( $handle, false );
			++$lines;
		}

		return $lines;
	}

	/**
	 * Print a single task row to STDOUT.
	 *
	 * @param array $handle   Process handle.
	 * @param bool  $running  True if the task is still running.
	 */
	private function print_row( array $handle, bool $running ): void {
		$end     = $handle['end_time'] ?? microtime( true );
		$elapsed = $end - $handle['start_time'];

		if ( $running ) {
			$indicator = $this->spinner( $handle['start_time'] ) . " \033[33mRunning \033[0m";
		} elseif ( $handle['exit_code'] === 0 ) {
			$indicator = "\033[32m✓ Done   \033[0m";
		} else {
			$indicator = "\033[31m✗ Failed \033[0m";
		}

		printf(
			"%s  %-50s  %5.1fs  %s\n",
			$indicator,
			$this->truncate( $handle['hook'], 50 ),
			$elapsed,
			$this->truncate( $handle['last_line'], 60 )
		);
	}

	/**
	 * Return the current frame of a braille-dot spinner based on elapsed time.
	 *
	 * @param float $start_time Unix timestamp (with microseconds) when the task started.
	 * @return string Single Unicode spinner character.
	 */
	private function spinner( float $start_time ): string {
		static $frames = [ '⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏' ];
		$index = (int) ( ( microtime( true ) - $start_time ) * 10 ) % count( $frames );
		return $frames[ $index ];
	}

	/**
	 * Truncate $str to at most $max characters, appending '…' if needed.
	 *
	 * @param string $str  Input string.
	 * @param int    $max  Maximum character length.
	 * @return string
	 */
	private function truncate( string $str, int $max ): string {
		if ( mb_strlen( $str ) <= $max ) {
			return $str;
		}
		return mb_substr( $str, 0, $max - 1 ) . '…';
	}
}

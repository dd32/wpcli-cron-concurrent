# wpcli-cron-concurrent

A WP-CLI package that runs WordPress cron tasks concurrently instead of sequentially.

By default, `wp cron event run --all` processes each task one at a time. This package spawns parallel subprocesses so multiple cron hooks execute simultaneously, with a live progress display showing each task's status and output.

## Installation

```bash
wp package install dd32/wpcli-cron-concurrent
```

## Usage

```bash
# Run all pending cron tasks concurrently (default: 5 at a time)
wp cron-concurrent run

# Run only hooks matching a substring
wp cron-concurrent run --filter=woocommerce

# Limit to 3 concurrent tasks
wp cron-concurrent run --concurrent=3
```

## Options

| Option | Description | Default |
|---|---|---|
| `--filter=<string>` | Only run hooks whose name contains this string | _(all hooks)_ |
| `--concurrent=<n>` | Maximum number of tasks to run at the same time | 5 |

## Development

Requires Node.js (for wp-env) and Docker.

```bash
npm install
npm run env:start
npm test
npm run env:stop
```

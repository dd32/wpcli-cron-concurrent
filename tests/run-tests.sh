#!/usr/bin/env bash
# Integration tests for the wp cron-concurrent command.
#
# Prerequisites: wp-env must already be running.
#   npm run env:start
#   npm test
#
# Each test calls `wp-env run tests-cli wp ...` which executes WP-CLI inside
# the wp-env tests Docker container. The wp-cli.yml mapped into that container
# automatically loads the plugin, so no --require flag is needed.

set -euo pipefail

PASS=0
FAIL=0

# ── Helpers ──────────────────────────────────────────────────────────────────

WP() { npx wp-env run tests-cli wp "$@" 2>&1; }

check() {
    local name="$1"
    local expected="$2"
    local actual="$3"

    if echo "$actual" | grep -qF "$expected"; then
        echo "✓  $name"
        PASS=$(( PASS + 1 ))
    else
        echo "✗  $name"
        echo "   expected to contain: $expected"
        printf '   actual output:\n'
        echo "$actual" | sed 's/^/     /'
        FAIL=$(( FAIL + 1 ))
    fi
}

not_check() {
    local name="$1"
    local unexpected="$2"
    local actual="$3"

    if echo "$actual" | grep -qF "$unexpected"; then
        echo "✗  $name"
        echo "   expected NOT to contain: $unexpected"
        printf '   actual output:\n'
        echo "$actual" | sed 's/^/     /'
        FAIL=$(( FAIL + 1 ))
    else
        echo "✓  $name"
        PASS=$(( PASS + 1 ))
    fi
}

# ── Reset ─────────────────────────────────────────────────────────────────────

# Clear the WordPress cron queue so default scheduled events (wp_version_check,
# wp_update_plugins, etc.) don't interfere with the tests.
WP option delete cron > /dev/null 2>&1 || true

# ── Tests ─────────────────────────────────────────────────────────────────────

echo ""
echo "=== wp cron-concurrent integration tests ==="
echo ""

# 1. Command is registered and help is available.
out=$(WP help cron-concurrent || true)
check "command 'cron-concurrent' is registered" "cron-concurrent" "$out"
check "help lists 'run' subcommand" "run" "$out"

# 2. 'run' help shows --filter and --concurrent options.
out=$(WP help cron-concurrent run || true)
check "run help shows --filter option" "--filter" "$out"
check "run help shows --concurrent option" "--concurrent" "$out"

# 3. No pending tasks produces the expected message.
out=$(WP cron-concurrent run || true)
check "no pending tasks message" "No pending cron tasks found." "$out"

# 4. Filter with no matching hooks also produces no-tasks message.
out=$(WP cron-concurrent run --filter=__nonexistent_hook_xyz__ || true)
check "filter with zero matches" "No pending cron tasks found." "$out"

# 5. Schedule two hooks and verify both are executed.
WP eval "wp_schedule_single_event( time() - 1, 'test_cc_hook_a' );" > /dev/null
WP eval "wp_schedule_single_event( time() - 1, 'test_cc_hook_b' );" > /dev/null
out=$(WP cron-concurrent run || true)
check "detects and runs pending tasks" "cron task(s) completed" "$out"

# 6. After running, events are consumed and queue is empty again.
out=$(WP cron-concurrent run || true)
check "queue empty after run" "No pending cron tasks found." "$out"

# 7. --filter limits execution to matching hooks only.
WP eval "wp_schedule_single_event( time() - 1, 'test_cc_filtered_hook' );" > /dev/null
WP eval "wp_schedule_single_event( time() - 1, 'test_cc_other_hook' );" > /dev/null
out=$(WP cron-concurrent run --filter=test_cc_filtered || true)
check "--filter runs matching hook" "cron task(s) completed" "$out"
# The other hook should still be pending (not consumed by this run).
remaining=$(WP cron event list --format=json || true)
check "non-matching hook still pending after filtered run" "test_cc_other_hook" "$remaining"
# Clean up remaining event.
WP cron-concurrent run > /dev/null || true

# 8. --concurrent=1 limits to a single concurrent task.
WP eval "wp_schedule_single_event( time() - 1, 'test_cc_seq_hook_a' );" > /dev/null
WP eval "wp_schedule_single_event( time() - 1, 'test_cc_seq_hook_b' );" > /dev/null
out=$(WP cron-concurrent run --concurrent=1 || true)
check "--concurrent=1 completes all tasks" "cron task(s) completed" "$out"

# ── Summary ──────────────────────────────────────────────────────────────────

echo ""
echo "Results: ${PASS} passed, ${FAIL} failed."
echo ""

[ "$FAIL" -eq 0 ]

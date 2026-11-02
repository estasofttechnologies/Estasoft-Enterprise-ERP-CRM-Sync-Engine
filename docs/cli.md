# WP-CLI

`wp estasoft-sync` is registered only while WP-CLI runs (`includes/Cli/CliModule.php`). The commands are in `includes/Cli/Commands.php`; every public method of that class is a subcommand and WP-CLI reads the options from the docblocks, so `wp help estasoft-sync worker` documents each one. Helpers are private.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | The command did what was asked. "Nothing to do" is also 0 and prints a warning (retrying nothing, resuming when nothing was paused), so a cron job does not raise an alarm. A worker that stops on SIGTERM, SIGINT, its item limit or its memory limit also exits 0, so a supervisor restarts it. |
| 1 | The command failed: nothing could be paused, the connected system could not be read, the comparison did not finish, or `readiness` found a check that needs fixing. |
| 2 | The arguments are wrong: a number outside its range, an unknown format, a malformed id or error code, `retry` with neither `--ids` nor `--code`, or an option written without a value (`--ids`, `--minutes=` or `--connection` alone, which WP-CLI would otherwise pass on as "yes" and which must never mean item 1, one minute or connection 1). Nothing was done. |

## Commands

### `wp estasoft-sync worker [--concurrency=<n>] [--max-items=<n>] [--max-memory=<mb>]`

Runs a worker that sends queued items until it is stopped or reaches a limit. It calls `Core\Queue\Worker::run()` (at most 20 seconds a run, the same claiming, ordering, retry, pause, circuit-breaker and rate-limit rules as the background runner), so it can run beside the scheduler or instead of it (Settings, Runtime profile, High volume).

- `--concurrency` items claimed at a time, 1 to 10, default 2; the lower of this and the setting "Items at once" applies.
- `--max-items` stop after claiming this many, default 500. The limit is checked between runs, so a run that is in progress finishes first.
- `--max-memory` stop when PHP uses this many megabytes, default 128, 16 to 8192.
- SIGTERM and SIGINT (when the `pcntl` extension is present) only set a flag; the worker finishes the run it is in and exits 0. Without `pcntl` it stops on its limits.
- When a run finds nothing to claim, the worker waits two seconds before the next. It prints a line for every run that claimed something, and a start and a stop line.

A supervisor keeps it running, for example systemd:

```ini
[Service]
ExecStart=/usr/local/bin/wp --path=/var/www/html estasoft-sync worker --max-items=500
Restart=always
RestartSec=2
```

### `wp estasoft-sync status [--connection=<id>] [--format=<table|csv|json|yaml>]`

Queue counts (synced today, waiting, needs attention, skipped, oldest waiting), the mode, and one row per connection with its status and the time a timed pause ends. `json` and `yaml` print the whole `Status` and the connection summaries.

### `wp estasoft-sync retry [--ids=<ids>] [--code=<code>] [--cause=<cause>]`

Sends failed or retrying items back to the queue. `--ids=10485,10491` retries those items; `--code=SYNC-MAP-001` retries every failed item with that code, and `--cause="SKU CS-LAMP-07"` narrows it to one group of Needs attention. Items keep their idempotency key and are sent oldest first, so a retry cannot create a second order. Each retry is written to the log.

### `wp estasoft-sync pause [--connection=<id>] [--minutes=<n>]`

Pauses all connections, or one. With `--minutes` (up to 43200) the pause ends by itself; without it, it lasts until `resume`. Items keep arriving and wait as Queued. Exit 1 when no connection can be paused.

### `wp estasoft-sync resume [--connection=<id>]`

Resumes all paused connections, or one. Also clears a pause caused by the circuit breaker and a "needs reconnect" status, because a person has acted.

### `wp estasoft-sync reconcile [--window=<days>] [--connection=<id>]`

The nightly check, now: compares the paid orders of the last `--window` days (1 to 30, default 7) with the connected system, read-only, and stores each difference for the Reconciliation screen. Orders missing in the system are queued again when automatic repair is on; amounts and refunds always wait for a person. The service works in bounded steps (about 15 seconds each); the command calls it again until the run is complete, prints the progress, and ends with the totals. Exit 1 when no connected system can be compared, when the system cannot be read, or when the comparison stops moving.

### `wp estasoft-sync readiness [--format=<table|csv|json|yaml>]`

Runs the ten readiness checks and prints them. Exit 1 when any check **needs fixing** (it blocks setup); a check that is only **worth fixing** does not change the exit code. Useful as a step in a deploy script.

## Tests

`tests/php/Unit/Cli/CommandsTest.php` runs every command against recorders. `tests/php/Integration/Cli/CliCommandsTest.php` runs them through the real `WpCliOutput` against the real services and database, with a small stand-in for the `WP_CLI` class (`tests/php/Support/RestWpCliShim.php`).

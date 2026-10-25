# Runway Queue Manager

A background job queue for [Runway](https://github.com/BaDClusteR/runway): the jobs are in a database table (SQLite
or MySQL / MariaDB), a worker runs them one by one, reports their progress and gets their cancellation. Made for small
servers: no daemon, no broker; the worker is started when a job is added, and by cron as a fallback.

```shell
composer require bad_cluster/runway-queue-manager:^1.0
php console queue:install    # creates the table, unless it exists
```

PHP 8.4+, Runway 2 (`bad_cluster/runway:^2.0.1`), Runway Console App (`bad_cluster/runway-console-app:^1.0.1`).

## Installation

The queue uses the database of the application (its `DSN`, `DB_USER`, `DB_PASSWORD`, `DB_PREFIX`), or its own one
(`QUEUE_DSN`, see Configuration). `queue:install --print` shows the SQL instead (see `sql/`).

Cron, in case the worker could not be started (a second worker of a queue exits at once), for the delayed retries
(see Retries), and to delete the old finished jobs (see Pruning):

```
* * * * * cd /path/to/project && php console queue:work > /dev/null 2>&1
30 4 * * * cd /path/to/project && php console queue:prune > /dev/null 2>&1
```

## Jobs

A handler runs the jobs of a type. It's a service with the `queue.job_handler` tag:

```php
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\JobContext;

class BackupJobHandler extends AJobHandler {
    public function getType(): string {
        return 'backup';
    }

    // Removed from the database when the job starts: the handler has them in memory.
    public function getSecretPayloadKeys(): array {
        return ['password'];
    }

    public function handle(JobDTO $job, JobContext $context): array {
        foreach ($tables as $index => $table) {
            // Throws JobCancelledException if the job is cancelled: clean up in "finally".
            $context->reportProgress(['done' => $index, 'total' => count($tables)]);
            // ...
        }

        return ['file' => $file, 'size' => filesize($file)];    // the result of the job
    }
}
```

```yaml
services:
  App\Queue\BackupJobHandler:
    tags:
      - name: queue.job_handler
```

A job is added and the worker is started:

```php
$job = $jobQueue->enqueue('backup', ['password' => $password]);    // IJobQueue
$workerLauncher->launch();                                           // IQueueWorkerLauncher
```

Then `IJobQueue::get()` has its status (`queued`, `running`, `done`, `failed`, `cancelled`), the progress, the result or
the error; `requestCancel()` cancels it (a running job stops at its next progress report), `delete()` deletes it
(`IJobHandler::cleanUp()` is for what the job has made).

The secret payload keys (`getSecretPayloadKeys()`) are removed from the database when the job starts, when it's
cancelled before it starts, and when its worker dies. They are not in the logged traces either: the traces of the
failed jobs are logged without the argument values.

## Queues

Every job is in a queue, `main` by default (the `queue_default` parameter). A worker runs one queue, or several ones:

```php
$jobQueue->enqueue('thumbnails', ['folder' => $folder], 'media');
$workerLauncher->launch('media');
```

```bash
php console queue:work --queue=media
php console queue:work --queue=main,media
```

Only one worker runs a queue at a time (the lock file `var/queue/<queue>.lock`): the jobs are often heavy, and a small
server would not cope with several ones at once. The workers of different queues run side by side. The jobs left
"running" by a worker that has died (e.g. killed for the memory) fail when the next worker of the queue starts.

A queue name is 1-50 letters, digits, `.`, `_` and `-`: it's a part of a lock file name and of a command line.

## Retries

A job fails at once by default. A handler whose jobs are worth another try (e.g. a notification sent to a service
that is down for a while) implements `IRetryingJobHandler`: the delays before the retries, in seconds.

```php
class SlackMessageJobHandler extends AJobHandler implements IRetryingJobHandler {
    // Up to 4 runs: the 2nd one a minute after the 1st one fails, then in 5 and 30 minutes.
    public function getRetryDelays(): array {
        return [60, 300, 1800];
    }
    // ...
}
```

A failed job is queued again with the error of the attempt, and is not run before its delay: the worker does not wait
for it, the cron one runs it (a retry without a delay is run by the same worker). `JobDTO::$attempts` counts the runs.
The failed attempts are logged as info, only the last failure is an error. The job ID is in the context of the log
record, not in its text: the failures of a type read the same (e.g. a log watcher of the application can group them,
and an alert about a failed notification does not become a new notification that fails too). The secret payload keys
of a retried job stay until it's done, failed or cancelled: the next attempt needs them.

## Pruning

The finished jobs (done, failed, cancelled) are kept for their results and errors; `queue:prune` deletes the ones
finished more than `--days` (30) ago, with what they have made (`IJobHandler::cleanUp()`). A queue of many small jobs
would grow for ever without it.

## Upgrading

A newer version of the library may add columns to the table: the worker adds them when it starts (and so does
`queue:install`), no manual step is needed. Until then, the jobs are added and read as before.

## Console

- `queue:work [--queue=a,b] [--once]`: runs the queued jobs until there are none.
- `queue:list [--queue=a,b] [--limit=20]`: the recent jobs.
- `queue:prune [--days=30]`: deletes the old finished jobs (`--days` must be a non-negative integer: a typo is an
  error, not 0 days).
- `queue:install [--print]`: creates the table, or adds the columns of a newer version to it.

## Configuration

The ENV variables (see `.env`, override them in the project):

- `QUEUE_DSN`, `QUEUE_DB_USER`, `QUEUE_DB_PASSWORD`, `QUEUE_DB_PREFIX`: the own database of the queue table. Empty
  `QUEUE_DSN`: the database of the application.
- `QUEUE_WORKER_AUTOSTART`: `false` - the worker is started by cron only. On Windows, the worker is never started in
  the background (no shell for it): run `queue:work` by the Task Scheduler.
- `QUEUE_PHP_BINARY`: the PHP CLI binary the worker is started with. By default, the one next to the PHP of the web
  server.

The parameters:

- `queue_table`: the table name without the prefix, `jobs` by default.
- `queue_default`: the default queue, `main`.
- `queue_lock_folder`: the folder of the lock files, `var/queue` of the project by default (`PROJECT_ROOT`, or the
  current folder).
- `queue_console_path`: the console script the worker is started with, `console` of the project by default.

## Development

```shell
composer test               # PHPUnit: the unit, integration and E2E tests
composer test:unit          # the classes one by one, SQLite in memory
composer test:integration   # the container in the process
composer test:e2e           # a project with its console script: the workers in separate processes
composer phpstan            # PHPStan, the max level
composer infection          # mutation testing of the unit tests (pcov or Xdebug)
```

The tests need the `pdo_sqlite` extension. The queue tests run on MySQL / MariaDB too if `QUEUE_TEST_MYSQL_DSN` is set
(e.g. `mysql:unix_socket=/tmp/mysql.sock;dbname=queue_test`, with `QUEUE_TEST_MYSQL_USER` and
`QUEUE_TEST_MYSQL_PASSWORD`): the test table is dropped and created again. The CI runs the tests on Linux, macOS and
Windows with PHP 8.4 and 8.5, and on MySQL 8.4 and MariaDB 11.8.

# Runway Queue Manager

A background job queue for [Runway](https://github.com/BaDClusteR/runway): the jobs are in a database table (SQLite
or MySQL / MariaDB), a worker runs them one by one, reports their progress and gets their cancellation. Made for small
servers: no daemon, no broker; the worker is started when a job is added, and by cron as a fallback.

## Installation

```bash
composer require bad_cluster/runway-queue-manager
php console queue:install    # creates the table, unless it exists
```

The queue uses the database of the application (its `DSN`, `DB_USER`, `DB_PASSWORD`, `DB_PREFIX`), or its own one
(`QUEUE_DSN`, see Configuration). `queue:install --print` shows the SQL instead (see `sql/`).

Cron, in case the worker could not be started (a second worker of a queue exits at once):

```
* * * * * cd /path/to/project && php console queue:work > /dev/null 2>&1
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

## Console

- `queue:work [--queue=a,b] [--once]`: runs the queued jobs until there are none.
- `queue:list [--queue=a,b] [--limit=20]`: the recent jobs.
- `queue:install [--print]`: creates the table.

## Configuration

The ENV variables (see `.env`, override them in the project):

- `QUEUE_DSN`, `QUEUE_DB_USER`, `QUEUE_DB_PASSWORD`, `QUEUE_DB_PREFIX`: the own database of the queue table. Empty
  `QUEUE_DSN`: the database of the application.
- `QUEUE_WORKER_AUTOSTART`: `false` - the worker is started by cron only.
- `QUEUE_PHP_BINARY`: the PHP CLI binary the worker is started with. By default, the one next to the PHP of the web
  server.

The parameters:

- `queue_table`: the table name without the prefix, `jobs` by default.
- `queue_default`: the default queue, `main`.
- `queue_lock_folder`: the folder of the lock files, `var/queue` of the project by default (`PROJECT_ROOT`, or the
  current folder).
- `queue_console_path`: the console script the worker is started with, `console` of the project by default.

## Tests

```bash
vendor/bin/phpunit
```

The queue tests run on SQLite in memory, and on MySQL / MariaDB too if `QUEUE_TEST_MYSQL_DSN` is set (e.g.
`mysql:unix_socket=/tmp/mysql.sock;dbname=queue_test`, with `QUEUE_TEST_MYSQL_USER` and `QUEUE_TEST_MYSQL_PASSWORD`).
The test table is dropped and created again.

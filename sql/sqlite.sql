-- The queue table for SQLite 3.37+ (STRICT). `{jobs}` gets the table prefix (see IQueueStorage).

CREATE TABLE IF NOT EXISTS `{jobs}` (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    -- The queue the job is in (see IJobQueue::enqueue()): a worker runs the jobs of its queues only.
    queue TEXT NOT NULL DEFAULT 'main',
    -- The handler (see IJobHandler::getType()), e.g. "export".
    type TEXT NOT NULL,
    -- queued, running, done, failed, cancelled (see JobStatus).
    status TEXT NOT NULL DEFAULT 'queued',
    -- JSON: the job parameters. The secret ones (e.g. a password) are removed when the job starts.
    payload TEXT NOT NULL DEFAULT '{}',
    -- JSON: the last progress reported by the handler.
    progress TEXT,
    -- JSON: the result of a done job.
    result TEXT,
    -- The error of a failed job.
    error TEXT,
    -- A running job is cancelled by the handler, at its next progress report.
    is_cancel_requested INTEGER NOT NULL DEFAULT 0,
    -- The runs of the job: a failed one is run again if its handler retries (see IRetryingJobHandler).
    attempts INTEGER NOT NULL DEFAULT 0,
    -- Unix timestamp: a queued job is not run before it (the delay of a retry).
    available INTEGER NOT NULL DEFAULT 0,
    -- Unix timestamps.
    created INTEGER NOT NULL,
    started INTEGER,
    finished INTEGER,
    updated INTEGER NOT NULL
) STRICT;

-- The next queued job of a queue.
CREATE INDEX IF NOT EXISTS `{jobs}_queue_status_IDX` ON `{jobs}` (queue, status, id);

-- The queue table for MySQL / MariaDB. `{jobs}` gets the table prefix (see IQueueStorage). The columns are the ones of
-- sqlite.sql.

CREATE TABLE IF NOT EXISTS `{jobs}` (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(50) NOT NULL DEFAULT 'main',
    type VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    payload MEDIUMTEXT NOT NULL,
    progress TEXT NULL,
    result MEDIUMTEXT NULL,
    error TEXT NULL,
    is_cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
    created INT UNSIGNED NOT NULL,
    started INT UNSIGNED NULL,
    finished INT UNSIGNED NULL,
    updated INT UNSIGNED NOT NULL,
    KEY `{jobs}_queue_status_IDX` (queue, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

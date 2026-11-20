<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Health Checks
    |--------------------------------------------------------------------------
    |
    | Thresholds of the hourly `system:health` command (routes/console.php,
    | T12.4). Each failed check is reported as its own Sentry issue, so an
    | alert rule on new issues is enough to be told about it.
    |
    | min_free_disk_percent: the disk holding storage/ (uploads, logs,
    | local backups) must keep at least this share of its space free.
    |
    | queue_max_wait_minutes: a queued job (guest and admin emails) that has
    | been ready for longer than this means no queue worker is running.
    |
    */

    'min_free_disk_percent' => (int) env('MONITORING_MIN_FREE_DISK_PERCENT', 10),

    'queue_max_wait_minutes' => (int) env('MONITORING_QUEUE_MAX_WAIT_MINUTES', 15),

];

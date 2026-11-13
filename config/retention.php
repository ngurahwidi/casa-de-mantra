<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | How long old operational data is kept before the scheduled prune jobs
    | (routes/console.php) delete it. Reservations, payments, status logs and
    | stored transfer proofs are never pruned.
    |
    | notification_log_days: email delivery logs of reservations that are
    | finished (completed, rejected, cancelled or expired). Logs of
    | reservations still in progress are kept, since they stop a reminder
    | from being sent twice.
    |
    | outbox_event_days: outbox events that were processed.
    |
    | temporary_upload_hours: files left behind by an upload that was never
    | saved, such as a transfer proof chosen in the admin panel when the
    | payment was then refused.
    |
    */

    'notification_log_days' => (int) env('RETENTION_NOTIFICATION_LOG_DAYS', 365),

    'outbox_event_days' => (int) env('RETENTION_OUTBOX_EVENT_DAYS', 90),

    'temporary_upload_hours' => (int) env('RETENTION_TEMPORARY_UPLOAD_HOURS', 24),

];

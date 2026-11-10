<?php

namespace App\Enums;

/**
 * Delivery state of a single notification (notification_logs.status).
 */
enum NotificationLogStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    /** A reminder that no longer applied when it was due to be sent, so it was not sent. */
    case Skipped = 'skipped';
}

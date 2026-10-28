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
}

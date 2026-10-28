<?php

namespace App\Enums;

/**
 * Delivery channel of a notification (notification_templates.channel, notification_logs.channel).
 */
enum NotificationChannel: string
{
    case Email = 'email';
}

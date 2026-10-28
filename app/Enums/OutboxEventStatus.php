<?php

namespace App\Enums;

/**
 * Processing state of an outbox event (outbox_events.status).
 */
enum OutboxEventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}

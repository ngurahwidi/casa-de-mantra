<?php

namespace App\Exceptions\Monitoring;

use Throwable;

/**
 * backup:run or backup:monitor found a problem: a backup could not be made, or the newest
 * backup on a disk is too old or the backups take too much space.
 */
class BackupFailedException extends SystemHealthException
{
    public static function because(Throwable $previous, ?string $diskName): self
    {
        return new self(
            'The backup failed'.($diskName !== null ? " on disk {$diskName}" : '').': '.$previous->getMessage(),
            ['disk' => $diskName],
            $previous,
        );
    }

    /**
     * @param  list<string>  $failures
     */
    public static function unhealthy(string $diskName, array $failures): self
    {
        return new self(
            "The backups on disk {$diskName} are unhealthy: ".implode(' ', $failures),
            ['disk' => $diskName, 'failures' => $failures],
        );
    }
}

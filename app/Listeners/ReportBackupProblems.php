<?php

namespace App\Listeners;

use App\Exceptions\Monitoring\BackupFailedException;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * spatie/laravel-backup emails backup problems but does not report them as exceptions, so
 * Sentry would never hear of them. This passes them to report() as well (T12.4).
 */
class ReportBackupProblems
{
    public function handleBackupHasFailed(BackupHasFailed $event): void
    {
        report(BackupFailedException::because($event->exception, $event->diskName));
    }

    public function handleUnhealthyBackupWasFound(UnhealthyBackupWasFound $event): void
    {
        report(BackupFailedException::unhealthy(
            $event->diskName,
            $event->failureMessages->pluck('message')->values()->all(),
        ));
    }
}

<?php

namespace Tests\Feature\Listeners;

use App\Exceptions\Monitoring\BackupFailedException;
use Exception;
use Illuminate\Support\Facades\Exceptions;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Tests\TestCase;

/**
 * Backup problems are reported (and so reach Sentry), not only emailed (T12.4).
 */
class ReportBackupProblemsTest extends TestCase
{
    public function test_a_failed_backup_is_reported_with_its_cause(): void
    {
        Exceptions::fake();
        $cause = new Exception('mysqldump: Got error 2002');

        event(new BackupHasFailed($cause, 'backups', 'Casa de Mantra'));

        Exceptions::assertReported(fn (BackupFailedException $reported): bool => $reported->getPrevious() === $cause
            && $reported->getMessage() === 'The backup failed on disk backups: mysqldump: Got error 2002');
    }

    public function test_an_unhealthy_backup_is_reported_with_the_failed_checks(): void
    {
        Exceptions::fake();

        event(new UnhealthyBackupWasFound('backups', 'Casa de Mantra', collect([
            ['check' => 'MaximumAgeInDays', 'message' => 'The latest backup is too old.'],
        ])));

        Exceptions::assertReported(fn (BackupFailedException $reported): bool => $reported->context() === [
            'disk' => 'backups',
            'failures' => ['The latest backup is too old.'],
        ]);
    }
}

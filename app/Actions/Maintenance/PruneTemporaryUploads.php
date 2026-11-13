<?php

namespace App\Actions\Maintenance;

use App\Models\Payment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Deletes uploaded files that were never saved to a record. Meant to run daily (T9.1).
 *
 * Two kinds of files are left behind:
 *
 * - Transfer proofs on the proof disk that no payment points at. The admin panel stores the
 *   file chosen in "Record Payment" before RecordPayment runs, so a payment that is refused
 *   (an expired reservation, for example) leaves its file without a payment row.
 * - Livewire temporary uploads, where every admin upload waits until its form is submitted.
 *   Livewire clears them only when another upload starts, and not at all on S3, where a bucket
 *   lifecycle rule is expected to do it, so S3 is left alone here too.
 *
 * Only files older than retention.temporary_upload_hours are deleted, so a file whose payment
 * is still being written, or whose form is still open, is never touched. A proof that belongs to
 * a payment is kept whatever its status, including rejected (failed) and expired proofs.
 */
class PruneTemporaryUploads
{
    /**
     * @return array{proofs: int, temporary: int} the number of files deleted of each kind
     */
    public function handle(): array
    {
        $cutoff = now()->subHours((int) config('retention.temporary_upload_hours'))->getTimestamp();

        return [
            'proofs' => $this->pruneOrphanedProofs($cutoff),
            'temporary' => $this->pruneLivewireUploads($cutoff),
        ];
    }

    private function pruneOrphanedProofs(int $cutoff): int
    {
        $disk = Storage::disk(Payment::PROOF_DISK);
        $referenced = Payment::query()->whereNotNull('proof_url')->pluck('proof_url')->flip();

        return $this->deleteOlderThan(
            $disk,
            $disk->allFiles(Payment::PROOF_DIRECTORY),
            $cutoff,
            fn (string $path): bool => ! $referenced->has($path),
        );
    }

    private function pruneLivewireUploads(int $cutoff): int
    {
        if (FileUploadConfiguration::isUsingS3()) {
            return 0;
        }

        $disk = FileUploadConfiguration::storage();

        return $this->deleteOlderThan($disk, $disk->allFiles(FileUploadConfiguration::path()), $cutoff);
    }

    /**
     * @param  list<string>  $paths
     * @param  (callable(string): bool)|null  $isPrunable
     */
    private function deleteOlderThan(Filesystem $disk, array $paths, int $cutoff, ?callable $isPrunable = null): int
    {
        $deleted = 0;

        foreach ($paths as $path) {
            if ($isPrunable !== null && ! $isPrunable($path)) {
                continue;
            }

            // Another process (Livewire's own cleanup) may have deleted it after the listing.
            if (! $disk->exists($path) || $disk->lastModified($path) >= $cutoff) {
                continue;
            }

            $deleted += (int) $disk->delete($path);
        }

        return $deleted;
    }
}

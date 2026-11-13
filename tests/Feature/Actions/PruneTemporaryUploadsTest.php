<?php

namespace Tests\Feature\Actions;

use App\Actions\Maintenance\PruneTemporaryUploads;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retention is 24 hours: at 2026-11-11 08:00, files last modified before 2026-11-10 08:00 go.
 */
class PruneTemporaryUploadsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-11 08:00:00';

    private const OLD = '2026-11-10 07:59:59';

    private const RECENT = '2026-11-10 08:00:00';

    /**
     * @return array<string, array{PaymentStatus}>
     */
    public static function paymentStatuses(): array
    {
        return collect(PaymentStatus::cases())
            ->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => [$status]])
            ->all();
    }

    public function test_an_old_proof_without_a_payment_is_deleted(): void
    {
        $this->travelTo(self::NOW);
        $disk = Storage::fake(Payment::PROOF_DISK);
        $orphan = $this->putFile($disk, Payment::PROOF_DIRECTORY.'/orphan.jpg', self::OLD);

        $deleted = app(PruneTemporaryUploads::class)->handle();

        $this->assertSame(['proofs' => 1, 'temporary' => 0], $deleted);
        $disk->assertMissing($orphan);
    }

    public function test_a_recent_proof_without_a_payment_is_kept(): void
    {
        $this->travelTo(self::NOW);
        $disk = Storage::fake(Payment::PROOF_DISK);
        $recent = $this->putFile($disk, Payment::PROOF_DIRECTORY.'/recent.jpg', self::RECENT);

        $deleted = app(PruneTemporaryUploads::class)->handle();

        $this->assertSame(['proofs' => 0, 'temporary' => 0], $deleted);
        $disk->assertExists($recent);
    }

    #[DataProvider('paymentStatuses')]
    public function test_a_proof_of_a_payment_is_kept_whatever_its_status(PaymentStatus $status): void
    {
        $this->travelTo(self::NOW);
        $disk = Storage::fake(Payment::PROOF_DISK);
        $proof = $this->putFile($disk, Payment::PROOF_DIRECTORY.'/ABCD2345/proof.jpg', self::OLD);
        Payment::factory()->create(['status' => $status, 'proof_url' => $proof]);

        $deleted = app(PruneTemporaryUploads::class)->handle();

        $this->assertSame(['proofs' => 0, 'temporary' => 0], $deleted);
        $disk->assertExists($proof);
    }

    public function test_files_outside_the_proof_directory_are_left_alone(): void
    {
        $this->travelTo(self::NOW);
        $disk = Storage::fake(Payment::PROOF_DISK);
        $other = $this->putFile($disk, 'exports/report.csv', self::OLD);

        app(PruneTemporaryUploads::class)->handle();

        $disk->assertExists($other);
    }

    public function test_old_livewire_temporary_uploads_are_deleted_and_recent_ones_kept(): void
    {
        $this->travelTo(self::NOW);
        Storage::fake(Payment::PROOF_DISK);
        $disk = FileUploadConfiguration::storage();
        $old = $this->putFile($disk, FileUploadConfiguration::path('old.jpg'), self::OLD);
        $recent = $this->putFile($disk, FileUploadConfiguration::path('recent.jpg'), self::RECENT);

        $deleted = app(PruneTemporaryUploads::class)->handle();

        $this->assertSame(['proofs' => 0, 'temporary' => 1], $deleted);
        $disk->assertMissing($old);
        $disk->assertExists($recent);
    }

    public function test_livewire_temporary_uploads_on_s3_are_left_to_the_bucket(): void
    {
        $this->travelTo(self::NOW);
        Storage::fake(Payment::PROOF_DISK);
        config(['livewire.temporary_file_upload.disk' => 's3', 'filesystems.disks.s3.driver' => 's3']);
        $disk = FileUploadConfiguration::storage();
        $old = $this->putFile($disk, FileUploadConfiguration::path('old.jpg'), self::OLD);

        $deleted = app(PruneTemporaryUploads::class)->handle();

        $this->assertSame(['proofs' => 0, 'temporary' => 0], $deleted);
        $disk->assertExists($old);
    }

    private function putFile(Filesystem $disk, string $path, string $modifiedAt): string
    {
        $disk->put($path, 'file');
        touch($disk->path($path), strtotime($modifiedAt.' UTC'));

        return $path;
    }
}

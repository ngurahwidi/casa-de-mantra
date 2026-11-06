<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationLogStatus;
use App\Filament\Resources\CancellationPolicies\Pages\ManageCancellationPolicies;
use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Filament\Resources\NotificationTemplates\Pages\ManageNotificationTemplates;
use App\Models\CancellationPolicy;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function indexUrls(): array
    {
        return [
            'ical feeds' => ['/admin/ical-feeds'],
            'cancellation policies' => ['/admin/cancellation-policies'],
            'notification templates' => ['/admin/notification-templates'],
            'notification logs' => ['/admin/notification-logs'],
            'users' => ['/admin/users'],
        ];
    }

    #[DataProvider('indexUrls')]
    public function test_index_pages_render(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_template_wording_is_edited_and_switched_off(): void
    {
        $template = NotificationTemplate::factory()->create(['code' => 'reservation_received', 'is_active' => true]);

        Livewire::test(ManageNotificationTemplates::class)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('delete')->table($template))
            ->callAction(TestAction::make('edit')->table($template), [
                'code' => 'reservation_confirmed',
                'subject' => 'Request {{ reservation_code }} received',
                'body' => 'Dear {{ guest_name }}, thank you.',
                'is_active' => false,
            ])
            ->assertHasNoFormErrors();

        $template->refresh();
        $this->assertSame('reservation_received', $template->code);
        $this->assertSame('Request {{ reservation_code }} received', $template->subject);
        $this->assertSame('Dear {{ guest_name }}, thank you.', $template->body);
        $this->assertFalse($template->is_active);
    }

    public function test_template_needs_a_subject_and_body(): void
    {
        $template = NotificationTemplate::factory()->create();

        Livewire::test(ManageNotificationTemplates::class)
            ->callAction(TestAction::make('edit')->table($template), ['subject' => '', 'body' => ''])
            ->assertHasFormErrors(['subject' => 'required', 'body' => 'required']);
    }

    public function test_cancellation_policy_text_is_edited_but_its_name_is_kept(): void
    {
        $policy = CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);

        Livewire::test(ManageCancellationPolicies::class)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('delete')->table($policy))
            ->callAction(TestAction::make('edit')->table($policy), [
                'name' => 'Flexible',
                'description' => 'Payments are not refunded.',
            ])
            ->assertHasNoFormErrors();

        $policy->refresh();
        $this->assertSame(CancellationPolicy::NON_REFUNDABLE, $policy->name);
        $this->assertSame('Payments are not refunded.', $policy->description);
    }

    public function test_notification_logs_are_listed_newest_first_and_read_only(): void
    {
        PropertyProfile::factory()->create();
        $older = NotificationLog::factory()->create(['created_at' => '2026-11-01 08:00:00']);
        $newer = NotificationLog::factory()->create([
            'created_at' => '2026-11-02 08:00:00',
            'status' => NotificationLogStatus::Failed,
            'error_message' => 'Mailbox unavailable',
        ]);

        Livewire::test(ListNotificationLogs::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->assertSee('Mailbox unavailable')
            ->filterTable('status', NotificationLogStatus::Failed->value)
            ->assertCanSeeTableRecords([$newer])
            ->assertCanNotSeeTableRecords([$older]);

        $this->assertFalse(NotificationLogResource::canCreate());
        $this->assertFalse(NotificationLogResource::canEdit($newer));
        $this->assertFalse(NotificationLogResource::canDelete($newer));
    }
}

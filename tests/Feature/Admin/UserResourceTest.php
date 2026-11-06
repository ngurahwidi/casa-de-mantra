<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
    }

    public function test_owner_creates_a_staff_account(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction('create', ['name' => 'Made', 'email' => 'made@example.com', 'password' => 'long-secret-1'])
            ->assertHasNoFormErrors();

        $staff = User::where('email', 'made@example.com')->sole();
        $this->assertSame(UserRole::Staff, $staff->role);
        $this->assertTrue(Hash::check('long-secret-1', $staff->password));
    }

    public function test_new_account_needs_a_password_and_a_unique_email(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction('create', ['name' => 'Made', 'email' => $this->owner->email])
            ->assertHasFormErrors(['email' => 'unique', 'password' => 'required']);

        Livewire::test(ManageUsers::class)
            ->callAction('create', ['name' => 'Made', 'email' => 'made@example.com', 'password' => 'short'])
            ->assertHasFormErrors(['password']);

        $this->assertSame(1, User::count());
    }

    public function test_editing_without_a_password_keeps_the_current_one(): void
    {
        $staff = User::factory()->create(['password' => 'old-password-1']);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($staff), ['name' => 'Made Wirawan', 'role' => UserRole::Owner->value, 'password' => ''])
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertSame('Made Wirawan', $staff->name);
        $this->assertSame(UserRole::Owner, $staff->role);
        $this->assertTrue(Hash::check('old-password-1', $staff->password));
    }

    public function test_owner_keeps_their_own_role(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($this->owner), ['role' => UserRole::Staff->value])
            ->assertHasNoFormErrors();

        $this->assertSame(UserRole::Owner, $this->owner->fresh()->role);
    }

    public function test_owner_deletes_other_accounts_but_not_their_own(): void
    {
        $staff = User::factory()->create();

        Livewire::test(ManageUsers::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->owner))
            ->callAction(TestAction::make('delete')->table($staff));

        $this->assertModelMissing($staff);
        $this->assertModelExists($this->owner);
    }
}

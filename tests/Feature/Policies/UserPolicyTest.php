<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manages_other_accounts(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->create();

        $this->assertTrue($owner->can('viewAny', User::class));
        $this->assertTrue($owner->can('create', User::class));
        $this->assertTrue($owner->can('view', $other));
        $this->assertTrue($owner->can('update', $other));
        $this->assertTrue($owner->can('delete', $other));
    }

    public function test_owner_cannot_delete_their_own_account(): void
    {
        $owner = User::factory()->owner()->create();

        $this->assertTrue($owner->can('update', $owner));
        $this->assertFalse($owner->can('delete', $owner));
    }

    public function test_accounts_cannot_be_deleted_in_bulk(): void
    {
        $this->assertFalse(User::factory()->owner()->create()->can('deleteAny', User::class));
    }

    public function test_staff_cannot_see_or_change_accounts(): void
    {
        $staff = User::factory()->create();
        $other = User::factory()->owner()->create();

        $this->assertFalse($staff->can('viewAny', User::class));
        $this->assertFalse($staff->can('create', User::class));
        $this->assertFalse($staff->can('view', $other));
        $this->assertFalse($staff->can('update', $staff));
        $this->assertFalse($staff->can('delete', $other));
    }
}

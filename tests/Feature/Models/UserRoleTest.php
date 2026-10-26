<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_enum(): void
    {
        $this->assertSame(UserRole::Staff, User::factory()->create()->role);
        $this->assertSame(UserRole::Owner, User::factory()->owner()->create()->role);
    }

    public function test_is_owner_helper(): void
    {
        $this->assertTrue(User::factory()->owner()->make()->isOwner());
        $this->assertFalse(User::factory()->make()->isOwner());
    }

    public function test_unknown_role_is_rejected_by_the_database(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $user->id)->update(['role' => 'superadmin']);
    }

    public function test_seeded_admin_is_an_owner(): void
    {
        config(['admin.email' => 'owner@example.com', 'admin.password' => 'secret-pass-123']);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(UserRole::Owner, User::where('email', 'owner@example.com')->firstOrFail()->role);
    }
}

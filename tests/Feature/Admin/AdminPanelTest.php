<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_seeded_admin_can_open_the_panel(): void
    {
        config(['admin.email' => 'owner@example.com', 'admin.password' => 'secret-pass-123']);

        $this->seed(AdminUserSeeder::class);

        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_seeder_does_not_reset_an_existing_password(): void
    {
        config(['admin.email' => 'owner@example.com', 'admin.password' => 'first-password']);
        $this->seed(AdminUserSeeder::class);

        config(['admin.password' => 'second-password']);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('first-password', User::firstOrFail()->password));
    }

    public function test_seeder_fails_without_credentials(): void
    {
        config(['admin.email' => null, 'admin.password' => null]);

        $this->expectException(RuntimeException::class);

        $this->seed(AdminUserSeeder::class);
    }
}

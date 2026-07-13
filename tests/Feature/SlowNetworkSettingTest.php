<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Tests\TestCase;

class SlowNetworkSettingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['admin' => true]);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['admin' => false]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Setting exists and is readable
    // ──────────────────────────────────────────────────────────────────────────

    public function test_slow_network_threshold_setting_exists_after_seed(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();

        $this->assertDatabaseHas('settings', [
            'key'   => 'slow_network_threshold_kbps',
            'group' => 'system.shares',
        ]);
    }

    public function test_slow_network_threshold_default_value_is_500(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();

        $this->assertDatabaseHas('settings', [
            'key'   => 'slow_network_threshold_kbps',
            'value' => '500',
        ]);
    }

    public function test_slow_network_threshold_included_in_system_shares_group_response(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/settings/group/system.shares')
            ->assertStatus(200);

        $keys = collect($response->json('data.settings'))->pluck('key');
        $this->assertTrue($keys->contains('slow_network_threshold_kbps'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Setting is writable by admin
    // ──────────────────────────────────────────────────────────────────────────

    public function test_admin_can_update_slow_network_threshold(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();
        $admin = $this->makeAdmin();

        // Settings write API takes: { settings: [{ key, value }, ...] }
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/settings/', [
                'settings' => [['key' => 'slow_network_threshold_kbps', 'value' => '250']],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', [
            'key'   => 'slow_network_threshold_kbps',
            'value' => '250',
        ]);
    }

    public function test_setting_to_zero_disables_feature(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/settings/', [
                'settings' => [['key' => 'slow_network_threshold_kbps', 'value' => '0']],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', [
            'key'   => 'slow_network_threshold_kbps',
            'value' => '0',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Non-admin can READ the setting (system.shares is not credentials-restricted)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_non_admin_can_read_system_shares_group(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/settings/group/system.shares')
            ->assertStatus(200);

        $keys = collect($response->json('data.settings'))->pluck('key');
        $this->assertTrue($keys->contains('slow_network_threshold_kbps'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Non-admin cannot write the setting
    // ──────────────────────────────────────────────────────────────────────────

    public function test_non_admin_cannot_update_slow_network_threshold(): void
    {
        $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->assertSuccessful();
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings/', [
                'settings' => [['key' => 'slow_network_threshold_kbps', 'value' => '100']],
            ])
            ->assertStatus(403);
    }
}

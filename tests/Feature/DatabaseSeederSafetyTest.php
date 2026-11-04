<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_does_not_create_or_reset_a_known_login(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);

        $user = User::factory()->create([
            'email' => 'pelanggan@shinjoy.test',
            'password' => Hash::make('private-password-123'),
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('private-password-123', $user->fresh()->password));
        $this->assertDatabaseCount('users', 1);
    }
}

<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileDeletionThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_limit_survives_ip_rotation_and_blocks_before_staff_guard(): void
    {
        $user = User::factory()->create();
        DB::enableQueryLog();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
                ->deleteJson('/settings/profile', ['password' => 'wrong-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertSame(5, $this->guardWrites());
        DB::flushQueryLog();
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])
            ->deleteJson('/settings/profile', ['password' => 'password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame(0, $this->guardWrites());
        $this->assertNotNull($user->fresh());

        $other = User::factory()->create();
        DB::flushQueryLog();
        $this->actingAs($other)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertSame(1, $this->guardWrites());
        $this->assertNull($other->fresh());
    }

    public function test_shared_ip_limit_applies_across_accounts_before_staff_guard(): void
    {
        $users = User::factory()->count(5)->create();
        DB::enableQueryLog();
        foreach ($users->take(4) as $user) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
                    ->deleteJson('/settings/profile', ['password' => 'wrong-password'])
                    ->assertUnprocessable()->assertJsonValidationErrors('password');
            }
        }
        $this->assertSame(20, $this->guardWrites());
        DB::flushQueryLog();
        $this->actingAs($users[4])->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->deleteJson('/settings/profile', ['password' => 'password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame(0, $this->guardWrites());
        $this->assertNotNull($users[4]->fresh());

        DB::flushQueryLog();
        $this->actingAs($users[4])->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertSame(1, $this->guardWrites());
        $this->assertNull($users[4]->fresh());
    }

    private function guardWrites(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($query) => str_starts_with($query['query'], 'update "staff_access_guards"')));
    }
}

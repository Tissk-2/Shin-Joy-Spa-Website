<?php

namespace Tests\Feature\Telegram;

use App\Actions\Telegram\ActivateStaffLink;
use App\Actions\Telegram\ClaimStaffEnrollment;
use App\Actions\Telegram\IssueStaffEnrollment;
use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffLinkPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-08 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_sees_each_members_telegram_state_and_history(): void
    {
        $owner = User::factory()->owner()->create();
        $a = User::factory()->bookingStaff()->create();
        $b = User::factory()->bookingStaff()->create();
        $c = User::factory()->bookingStaff()->create();
        $d = User::factory()->bookingStaff()->create();
        $e = User::factory()->bookingStaff()->create();

        $this->link($owner, $a, '1234567890123');
        $token = (new IssueStaffEnrollment)($owner, $b->id);
        $claimed = (new ClaimStaffEnrollment)($token, '5550001111');
        (new IssueStaffEnrollment)($owner, $d->id);
        CarbonImmutable::setTestNow('2026-11-08 03:10:00 UTC');
        $waitingToken = (new IssueStaffEnrollment)($owner, $c->id);
        $waiting = TelegramEnrollment::query()->where('token_hash', hash('sha256', $waitingToken))->sole();

        CarbonImmutable::setTestNow('2026-11-08 03:16:00 UTC');
        $response = $this->actingAs($owner)->get('/admin/staff');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/staff/Index')
            ->has('staff.data', 6)
            ->where('telegramBotConfigured', false)
            ->where('freshTelegramLink', null)
            ->has('telegramHistory', 1)
            ->where('telegramHistory.0', [
                'action' => 'activated',
                'targetName' => $a->name,
                'targetDeleted' => false,
                'identity' => '0123',
                'actorName' => $owner->name,
                'at' => '2026-11-08T03:00:00Z',
            ]));

        $rows = collect($response->inertiaProps('staff.data'))->keyBy('id');

        $this->assertSame(['state' => 'linked', 'identity' => '0123', 'linkedAt' => '2026-11-08T03:00:00Z'], $rows[$a->id]['telegram']);
        $this->assertSame(['state' => 'claimed', 'enrollmentId' => $claimed->id, 'identity' => '1111', 'claimedAt' => '2026-11-08T03:00:00Z'], $rows[$b->id]['telegram']);
        $this->assertSame(['state' => 'none'], $rows[$d->id]['telegram']);
        $this->assertSame(['state' => 'waiting', 'enrollmentId' => $waiting->id, 'expiresAt' => '2026-11-08T03:25:00Z'], $rows[$c->id]['telegram']);
        $this->assertSame(['state' => 'none'], $rows[$e->id]['telegram']);

        $serialized = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('1234567890123', $serialized);
        $this->assertStringNotContainsString('5550001111', $serialized);
    }

    public function test_issuing_shows_a_one_time_link_once(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        config(['services.telegram.bot_username' => 'ShinJoyStaffBot']);

        $response = $this->actingAs($owner)->post("/admin/staff/{$staff->id}/telegram-enrollment");

        $response->assertRedirect('/admin/staff')->assertStatus(303)->assertSessionHas('telegramEnrollment');
        $flash = session('telegramEnrollment');
        $this->assertIsArray($flash);
        $this->assertSame($staff->id, $flash['staffId']);
        $this->assertSame(1, preg_match('/\A\/start ([A-Za-z0-9_-]{43})\z/', $flash['command'], $matches));
        $token = $matches[1];
        $this->assertSame('https://t.me/ShinJoyStaffBot?start='.$token, $flash['url']);
        $this->assertSame('2026-11-08T03:15:00Z', $flash['expiresAt']);
        $this->assertTrue(TelegramEnrollment::query()->where('token_hash', hash('sha256', $token))->exists());

        $first = $this->get('/admin/staff')->assertOk();
        $first->assertInertia(fn (Assert $page) => $page
            ->where('freshTelegramLink.staffId', $staff->id)
            ->where('freshTelegramLink.command', '/start '.$token)
            ->where('freshTelegramLink.url', $flash['url'])
            ->where('freshTelegramLink.expiresAt', '2026-11-08T03:15:00Z'));
        $this->get('/admin/staff')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('freshTelegramLink', null));

        $second = User::factory()->bookingStaff()->create();
        config(['services.telegram.bot_username' => 'bad name']);
        $this->post("/admin/staff/{$second->id}/telegram-enrollment")->assertRedirect('/admin/staff');
        $this->assertNull(session('telegramEnrollment')['url']);

        $third = User::factory()->bookingStaff()->create();
        config(['services.telegram.bot_username' => null]);
        $this->post("/admin/staff/{$third->id}/telegram-enrollment")->assertRedirect('/admin/staff');
        $this->assertNull(session('telegramEnrollment')['url']);
    }

    public function test_activate_and_revoke_through_the_staff_routes(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');

        $this->actingAs($owner)->post("/admin/telegram-enrollments/{$enrollment->id}/activate")
            ->assertRedirect('/admin/staff')->assertStatus(303)
            ->assertSessionHas('status', __('admin.telegram.activated'));
        $this->assertSame(1, TelegramStaffLink::query()->where('user_id', $staff->id)->count());
        $this->assertDatabaseHas('telegram_staff_link_changes', ['action' => 'activated', 'target_user_id' => $staff->id]);

        $this->delete("/admin/staff/{$staff->id}/telegram-link")
            ->assertRedirect('/admin/staff')->assertStatus(303)
            ->assertSessionHas('status', __('admin.telegram.revoked'));
        $this->assertDatabaseCount('telegram_staff_links', 0);
        $this->assertDatabaseHas('telegram_staff_link_changes', ['action' => 'revoked', 'target_user_id' => $staff->id]);
    }

    public function test_actions_return_to_the_page_that_shows_the_member(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->count(19)->bookingStaff()->create();
        User::factory()->count(20)->create();
        $last = User::factory()->bookingStaff()->create();
        $this->actingAs($owner);

        $this->post("/admin/staff/{$last->id}/telegram-enrollment")
            ->assertStatus(303)->assertRedirect('/admin/staff?page=2');
        $token = substr(session('telegramEnrollment')['command'], strlen('/start '));
        $this->get('/admin/staff?page=2')->assertInertia(fn (Assert $page) => $page
            ->where('staff.data.0.id', $last->id)
            ->where('freshTelegramLink.staffId', $last->id));

        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');
        $this->post("/admin/telegram-enrollments/{$enrollment->id}/activate")
            ->assertStatus(303)->assertRedirect('/admin/staff?page=2');
        $this->delete("/admin/staff/{$last->id}/telegram-link")
            ->assertStatus(303)->assertRedirect('/admin/staff?page=2');
    }

    public function test_only_a_valid_bot_username_counts_as_configured(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $this->actingAs($owner);

        config(['services.telegram.bot_username' => '@ShinJoyStaffBot']);
        $this->get('/admin/staff')->assertInertia(fn (Assert $page) => $page->where('telegramBotConfigured', false));
        $this->post("/admin/staff/{$staff->id}/telegram-enrollment");
        $this->assertNull(session('telegramEnrollment')['url']);

        config(['services.telegram.bot_username' => 'ShinJoyStaffBot']);
        $this->get('/admin/staff')->assertInertia(fn (Assert $page) => $page->where('telegramBotConfigured', true));
    }

    public function test_removing_a_link_that_was_never_activated_says_it_was_cancelled(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        (new IssueStaffEnrollment)($owner, $staff->id);

        $this->actingAs($owner)->delete("/admin/staff/{$staff->id}/telegram-link")
            ->assertStatus(303)->assertSessionHas('status', __('admin.telegram.cancelled'));
        $this->assertNotSame(__('admin.telegram.cancelled'), __('admin.telegram.revoked'));
        $this->assertNotSame('admin.telegram.cancelled', __('admin.telegram.cancelled'));
        $this->assertDatabaseCount('telegram_staff_link_changes', 0);
    }

    public function test_refusals_return_as_validation_errors(): void
    {
        $owner = User::factory()->owner()->create();
        $plain = User::factory()->admin()->create();
        $nothing = User::factory()->bookingStaff()->create();

        $this->actingAs($owner);

        $this->from('/admin/staff')->post("/admin/staff/{$plain->id}/telegram-enrollment")
            ->assertRedirect('/admin/staff')->assertSessionHasErrors('staff');
        $this->from('/admin/staff')->post('/admin/telegram-enrollments/999999/activate')
            ->assertRedirect('/admin/staff')->assertSessionHasErrors('enrollment');
        $this->from('/admin/staff')->delete("/admin/staff/{$nothing->id}/telegram-link")
            ->assertRedirect('/admin/staff')->assertSessionHasErrors('staff');

        $this->assertDatabaseCount('telegram_staff_links', 0);
    }

    public function test_only_the_owner_can_use_the_telegram_routes(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');

        $requests = [
            ['post', "/admin/staff/{$staff->id}/telegram-enrollment"],
            ['post', "/admin/telegram-enrollments/{$enrollment->id}/activate"],
            ['delete', "/admin/staff/{$staff->id}/telegram-link"],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect('/login');
        }

        $this->actingAs($staff);

        foreach ($requests as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }

        $this->assertDatabaseCount('telegram_staff_links', 0);
        $this->assertSame(1, TelegramEnrollment::query()->count());
        $this->assertNull($enrollment->fresh()->activated_at);
    }

    private function link(User $owner, User $staff, string $telegramId): TelegramStaffLink
    {
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, $telegramId);

        return (new ActivateStaffLink)($owner, $enrollment->id);
    }
}

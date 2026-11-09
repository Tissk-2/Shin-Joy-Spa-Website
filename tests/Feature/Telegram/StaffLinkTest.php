<?php

namespace Tests\Feature\Telegram;

use App\Actions\Staff\SetBookingPermission;
use App\Actions\Telegram\ActivateStaffLink;
use App\Actions\Telegram\ClaimStaffEnrollment;
use App\Actions\Telegram\IssueStaffEnrollment;
use App\Actions\Telegram\RevokeStaffLink;
use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffLinkTest extends TestCase
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

    public function test_owner_issues_a_one_use_token_for_capable_staff(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();

        $token = (new IssueStaffEnrollment)($owner, $staff->id);

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $enrollment = TelegramEnrollment::query()->sole();
        $this->assertSame($staff->id, $enrollment->user_id);
        $this->assertSame($owner->id, $enrollment->issued_by_user_id);
        $this->assertSame(hash('sha256', $token), $enrollment->token_hash);
        $this->assertSame('2026-11-08 03:15:00', $enrollment->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($enrollment->claimed_at);
        $this->assertNull($enrollment->activated_at);
        $this->assertDatabaseMissing('telegram_enrollments', ['token_hash' => $token]);
        // Review addition: the hash never leaves the model when C1b serializes it.
        $this->assertArrayNotHasKey('token_hash', $enrollment->toArray());

        (new IssueStaffEnrollment)($owner, $owner->id);

        $second = (new IssueStaffEnrollment)($owner, $staff->id);

        $this->assertNotSame($token, $second);
        $this->assertNotNull($enrollment->fresh()->cancelled_at);
        $this->assertRefused(fn () => (new ClaimStaffEnrollment)($token, '1234567890123'), 'token', 'admin.telegram.invalid_token');
    }

    public function test_only_a_verified_owner_issues_activates_and_revokes(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');
        $actors = [User::factory()->bookingStaff()->create(), User::factory()->owner()->unverified()->create()];

        foreach ($actors as $actor) {
            foreach ([
                fn () => (new IssueStaffEnrollment)($actor, $staff->id),
                fn () => (new ActivateStaffLink)($actor, $enrollment->id),
                fn () => (new RevokeStaffLink)($actor, $staff->id),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('A non-owner managed a Telegram link.');
                } catch (AuthorizationException) {
                }
            }
        }

        $this->assertDatabaseCount('telegram_staff_links', 0);
        $this->assertDatabaseCount('telegram_staff_link_changes', 0);
        $this->assertSame(1, TelegramEnrollment::query()->count());
        $this->assertNull($enrollment->fresh()->activated_at);
    }

    public function test_targets_must_be_verified_staff_who_can_manage_bookings(): void
    {
        $owner = User::factory()->owner()->create();
        $targets = [
            User::factory()->admin()->create()->id,
            User::factory()->bookingStaff()->unverified()->create()->id,
            User::factory()->create()->id,
            999999,
        ];

        foreach ($targets as $id) {
            $this->assertRefused(fn () => (new IssueStaffEnrollment)($owner, $id), 'staff', 'admin.telegram.invalid_target');
        }

        $this->assertDatabaseCount('telegram_enrollments', 0);
    }

    public function test_claims_are_single_use_expire_and_fail_alike(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);

        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');

        $this->assertSame('1234567890123', $enrollment->claimed_telegram_user_id);
        $this->assertSame('2026-11-08 03:00:00', $enrollment->claimed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertRefused(fn () => (new ClaimStaffEnrollment)($token, '9999999999999'), 'token', 'admin.telegram.invalid_token');

        $other = User::factory()->bookingStaff()->create();
        $expiring = (new IssueStaffEnrollment)($owner, $other->id);
        CarbonImmutable::setTestNow('2026-11-08 03:15:00 UTC');
        $this->assertRefused(fn () => (new ClaimStaffEnrollment)($expiring, '2222222222222'), 'token', 'admin.telegram.invalid_token');

        // Review addition: anyone can send the bot /start, so an unknown token must be refused
        // without taking the staff guard that dashboard approvals also wait on.
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $this->assertRefused(fn () => (new ClaimStaffEnrollment)(str_repeat('a', 43), '3333333333333'), 'token', 'admin.telegram.invalid_token');
        $this->assertSame([], preg_grep('/staff_access_guards/i', $statements));

        $this->assertRefused(fn () => (new ClaimStaffEnrollment)(str_repeat('a', 42), '4444444444444'), 'token', 'admin.telegram.invalid_token');
        foreach (['abc', '0', '-5', '0123', str_repeat('9', 21)] as $identity) {
            $this->assertRefused(fn () => (new ClaimStaffEnrollment)($token, $identity), 'telegram', 'admin.telegram.invalid_identity');
        }
    }

    public function test_owner_activates_a_claimed_enrollment_into_one_current_link(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');

        $link = (new ActivateStaffLink)($owner, $enrollment->id);

        $this->assertSame($staff->id, $link->user_id);
        $this->assertSame('1234567890123', $link->telegram_user_id);
        $this->assertDatabaseCount('telegram_staff_links', 1);
        $enrollment->refresh();
        $this->assertSame('2026-11-08 03:00:00', $enrollment->activated_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($owner->id, $enrollment->activated_by_user_id);
        $this->assertDatabaseHas('telegram_staff_link_changes', [
            'action' => 'activated',
            'actor_user_id' => $owner->id,
            'target_user_id' => $staff->id,
            'target_name' => $staff->name,
            'telegram_user_id' => '1234567890123',
        ]);
        $this->assertRefused(fn () => (new ActivateStaffLink)($owner, $enrollment->id), 'enrollment', 'admin.telegram.not_claimed');

        $pendingStaff = User::factory()->bookingStaff()->create();
        $pendingToken = (new IssueStaffEnrollment)($owner, $pendingStaff->id);
        $pending = TelegramEnrollment::query()->where('token_hash', hash('sha256', $pendingToken))->sole();
        $this->assertRefused(fn () => (new ActivateStaffLink)($owner, $pending->id), 'enrollment', 'admin.telegram.not_claimed');
        $this->assertNull($pending->fresh()->activated_at);
    }

    public function test_telegram_identity_collisions_are_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $a = User::factory()->bookingStaff()->create();
        $b = User::factory()->bookingStaff()->create();
        $c = User::factory()->bookingStaff()->create();
        $d = User::factory()->bookingStaff()->create();

        $this->link($owner, $a, '111');
        $this->assertRefused(fn () => (new IssueStaffEnrollment)($owner, $a->id), 'staff', 'admin.telegram.already_linked');

        $bToken = (new IssueStaffEnrollment)($owner, $b->id);
        $this->assertRefused(fn () => (new ClaimStaffEnrollment)($bToken, '111'), 'telegram', 'admin.telegram.telegram_in_use');

        $cToken = (new IssueStaffEnrollment)($owner, $c->id);
        $cEnrollment = (new ClaimStaffEnrollment)($cToken, '333');
        $dToken = (new IssueStaffEnrollment)($owner, $d->id);
        $dEnrollment = (new ClaimStaffEnrollment)($dToken, '333');

        $cLink = (new ActivateStaffLink)($owner, $cEnrollment->id);
        $this->assertSame('333', $cLink->telegram_user_id);
        $this->assertRefused(fn () => (new ActivateStaffLink)($owner, $dEnrollment->id), 'telegram', 'admin.telegram.telegram_in_use');
        $this->assertSame(1, TelegramStaffLink::query()->where('telegram_user_id', '333')->count());
    }

    public function test_activation_rechecks_the_staff_member(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, '1234567890123');

        (new SetBookingPermission)($owner, $staff->id, false);

        $this->assertRefused(fn () => (new ActivateStaffLink)($owner, $enrollment->id), 'staff', 'admin.telegram.invalid_target');
        $this->assertDatabaseCount('telegram_staff_links', 0);
    }

    public function test_revoke_removes_the_link_cancels_open_enrollments_and_keeps_history(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $other = User::factory()->bookingStaff()->create();
        $this->link($owner, $staff, '555');

        $pendingToken = (new IssueStaffEnrollment)($owner, $other->id);
        $pending = TelegramEnrollment::query()->where('token_hash', hash('sha256', $pendingToken))->sole();

        (new RevokeStaffLink)($owner, $staff->id);

        $this->assertDatabaseCount('telegram_staff_links', 0);
        $this->assertDatabaseHas('telegram_staff_link_changes', [
            'action' => 'revoked',
            'actor_user_id' => $owner->id,
            'target_user_id' => $staff->id,
            'target_name' => $staff->name,
            'telegram_user_id' => '555',
        ]);

        $this->assertSame('555', $this->link($owner, $other, '555')->telegram_user_id);

        $unlinked = User::factory()->bookingStaff()->create();
        $openToken = (new IssueStaffEnrollment)($owner, $unlinked->id);
        $open = TelegramEnrollment::query()->where('token_hash', hash('sha256', $openToken))->sole();
        (new RevokeStaffLink)($owner, $unlinked->id);
        $this->assertNotNull($open->fresh()->cancelled_at);

        $nothing = User::factory()->bookingStaff()->create();
        $this->assertRefused(fn () => (new RevokeStaffLink)($owner, $nothing->id), 'staff', 'admin.telegram.not_linked');

        // Review addition: a link can be removed after the staff member lost booking permission.
        (new SetBookingPermission)($owner, $other->id, false);
        (new RevokeStaffLink)($owner, $other->id);
        $this->assertDatabaseMissing('telegram_staff_links', ['user_id' => $other->id]);
    }

    // Review addition: after a leaked link is claimed, re-issuing or revoking cancels that claim;
    // a stale tab or a second owner must never be able to activate it afterwards.
    public function test_a_cancelled_enrollment_can_never_be_activated(): void
    {
        $owner = User::factory()->owner()->create();
        $reissued = User::factory()->bookingStaff()->create();
        $revoked = User::factory()->bookingStaff()->create();

        $hijacked = (new ClaimStaffEnrollment)((new IssueStaffEnrollment)($owner, $reissued->id), '999');
        (new IssueStaffEnrollment)($owner, $reissued->id);
        $alsoHijacked = (new ClaimStaffEnrollment)((new IssueStaffEnrollment)($owner, $revoked->id), '998');
        (new RevokeStaffLink)($owner, $revoked->id);

        foreach ([$hijacked, $alsoHijacked] as $enrollment) {
            $this->assertRefused(fn () => (new ActivateStaffLink)($owner, $enrollment->id), 'enrollment', 'admin.telegram.not_claimed');
            $this->assertNull($enrollment->fresh()->activated_at);
        }
        $this->assertDatabaseCount('telegram_staff_links', 0);
    }

    public function test_deleting_a_staff_account_removes_its_link_but_not_the_history(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $this->link($owner, $staff, '777');
        $staffId = $staff->id;
        $name = $staff->name;

        $staff->delete();

        $this->assertDatabaseMissing('telegram_staff_links', ['user_id' => $staffId]);
        $this->assertDatabaseMissing('telegram_enrollments', ['user_id' => $staffId]);
        $this->assertDatabaseHas('telegram_staff_link_changes', [
            'action' => 'activated',
            'target_user_id' => null,
            'target_name' => $name,
            'telegram_user_id' => '777',
        ]);
    }

    private function link(User $owner, User $staff, string $telegramId): TelegramStaffLink
    {
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $enrollment = (new ClaimStaffEnrollment)($token, $telegramId);

        return (new ActivateStaffLink)($owner, $enrollment->id);
    }

    private function assertRefused(callable $call, string $key, string $translation): void
    {
        try {
            $call();
            $this->fail("The action accepted an invalid request for [{$key}].");
        } catch (ValidationException $exception) {
            $this->assertSame(__($translation), $exception->errors()[$key][0]);
        }
    }
}

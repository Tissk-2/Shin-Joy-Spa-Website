<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'calmspa9';

    private const NEW_PASSWORD = 'NewCalmSpa9!';

    public function test_a_customer_session_is_revoked_after_the_password_changes_out_of_band(): void
    {
        $user = $this->verifiedUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ]);

        $this->get('/bookings')->assertOk();

        $this->changePasswordOutOfBand($user);

        $this->get('/bookings')->assertStatus(302);
        $this->assertGuest();
    }

    public function test_an_admin_session_is_revoked_after_the_password_changes_out_of_band(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => Hash::make(self::OLD_PASSWORD),
        ]);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => self::OLD_PASSWORD,
        ]);

        $this->get('/admin')->assertOk();

        $this->changePasswordOutOfBand($admin);

        $this->get('/admin')->assertStatus(302);
        $this->assertGuest();
    }

    public function test_the_session_that_changes_its_own_password_stays_signed_in(): void
    {
        $user = $this->verifiedUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ]);

        $this->put(route('settings.password.update'), [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->get('/bookings')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_remember_me_cookie_is_revoked_after_the_password_changes_out_of_band(): void
    {
        $user = $this->verifiedUser();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
            'remember' => true,
        ]);

        $recallerName = Auth::guard('web')->getRecallerName();
        $recaller = $response->getCookie($recallerName, false);

        $this->assertNotNull($recaller);

        // Positive control: the captured cookie authenticates before the change.
        $this->flushSession();
        Auth::forgetGuards();
        $this->withUnencryptedCookie($recallerName, $recaller->getValue())
            ->get('/bookings')
            ->assertOk();

        $this->changePasswordOutOfBand($user);
        $this->flushSession();

        $this->withUnencryptedCookie($recallerName, $recaller->getValue())
            ->get('/bookings')
            ->assertStatus(302);

        $this->assertGuest();
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'password' => Hash::make(self::OLD_PASSWORD),
        ]);
    }

    private function changePasswordOutOfBand(User $user): void
    {
        app(ResetUserPassword::class)->reset($user->fresh(), [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        // The test process caches the guard user between requests; a real later
        // request re-reads the user from the database.
        Auth::forgetGuards();
    }
}

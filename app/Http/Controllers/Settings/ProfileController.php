<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\FinalAdministratorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $emailChanged = false;
        (new StaffAccessLock)->run(function () use ($request, &$emailChanged): void {
            $emailChanged = false;
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $attributes = $request->safe()->only(['name', 'email', 'phone']);
            if ($user->email !== $attributes['email']) {
                StaffAuthority::protectEmailChange($user);
                Password::broker()->deleteToken($user);
                $attributes = [...$attributes, 'email_verified_at' => null];
                $emailChanged = true;
            }
            $user->forceFill($attributes)->save();
        });

        if (! $emailChanged) {
            return to_route('profile.edit');
        }

        // The new address gets its own link at once, and the account waits on the
        // verify page, so a mistyped address can be fixed from there too.
        try {
            $request->user()->refresh()->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);

            return to_route('verification.notice');
        }

        return to_route('verification.notice')->with('status', 'verification-link-sent');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $userId = $request->user()->getKey();

        try {
            (new StaffAccessLock)->run(function () use ($userId, $request): void {
                $user = User::query()
                    ->whereKey($userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! Hash::check($request->input('password'), $user->password)) {
                    throw ValidationException::withMessages(['password' => __('validation.current_password')]);
                }

                StaffAuthority::protectDeletion($user);

                Password::broker()->deleteToken($user);

                $user->delete();
            });
        } catch (FinalAdministratorException) {
            throw ValidationException::withMessages([
                'password' => __('auth.final_administrator'),
            ]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'account-deleted');
    }
}

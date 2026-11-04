<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\FinalAdministratorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffAuthority;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        (new StaffAccessLock)->run(function () use ($request): void {
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $attributes = $request->validated();
            if ($user->email !== $attributes['email']) {
                StaffAuthority::protectEmailChange($user);
                $attributes = [...$attributes, 'email_verified_at' => null];
            }
            $user->forceFill($attributes)->save();
        });

        return to_route('profile.edit');
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

        return redirect('/');
    }
}

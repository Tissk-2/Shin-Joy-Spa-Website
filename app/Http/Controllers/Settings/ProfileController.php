<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Exceptions\FinalAdministratorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $userId = $request->user()->getKey();

        try {
            DB::transaction(function () use ($userId): void {
                $adminIds = User::query()
                    ->where('role', UserRole::Admin->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                $user = User::query()
                    ->whereKey($userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($user->isAdmin() && $adminIds->count() <= 1) {
                    throw new FinalAdministratorException;
                }

                $user->delete();
            }, 3);
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

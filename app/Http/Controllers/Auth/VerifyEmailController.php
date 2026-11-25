<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Support\SafeIntendedPath;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect(SafeIntendedPath::pull($request) ?? route('profile', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            /** @var MustVerifyEmail $user */
            $user = $request->user();

            // After an email change, open bookings follow the verified address so their status
            // emails reach the customer; finished and cancelled ones keep the old one as a record.
            Booking::query()
                ->where('user_id', $user->getKey())
                ->whereIn('status', [BookingStatus::Pending, BookingStatus::AwaitingPayment, BookingStatus::Confirmed])
                ->update(['customer_email' => $user->getEmailForVerification()]);

            event(new Verified($user));
        }

        return redirect(SafeIntendedPath::pull($request) ?? route('profile', absolute: false).'?verified=1');
    }
}

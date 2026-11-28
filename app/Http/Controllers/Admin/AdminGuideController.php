<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/** In-panel operational guide for front-desk staff and owners. */
final class AdminGuideController extends Controller
{
    public function __invoke(): Response
    {
        return inertia('admin/Guide', [
            'roomSchedulingEnabled' => config('booking.scheduling_enabled') === true,
            'customerNoticesEnabled' => config('booking.customer_notices_enabled') === true,
            'telegramActionsEnabled' => config('services.telegram.actions_enabled') === true,
            'canCreateBooking' => Gate::allows('create', Booking::class),
        ]);
    }
}

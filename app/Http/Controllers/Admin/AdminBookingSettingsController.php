<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveBookingSettings;
use App\Http\Controllers\Controller;
use App\Queries\BookingSettingsQuery;
use App\Support\Rooms\RoomOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

final class AdminBookingSettingsController extends Controller
{
    public function edit(Request $request, BookingSettingsQuery $query): Response
    {
        return inertia('admin/settings/Booking', [...$query->get($request->user()), 'catalog' => ['packages' => []]]);
    }

    public function update(Request $request, SaveBookingSettings $save): RedirectResponse
    {
        (new RoomOwner)->authorize($request->user());
        $save($request->user(), $request->except(['_token', '_method']));

        return to_route('admin.settings.booking.edit')->with('status', __('admin.booking_settings.saved'));
    }
}

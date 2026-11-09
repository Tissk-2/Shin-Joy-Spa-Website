<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Queries\AdminScheduleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

final class AdminScheduleController extends Controller
{
    public function __invoke(Request $request, AdminScheduleQuery $query): Response
    {
        Gate::authorize('viewAny', Booking::class);
        $validated = $request->validate(['date' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $date = $validated['date'] ?? null;
        $day = $date === null ? $today : CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Jakarta');

        return inertia('admin/schedule/Index', ['schedule' => $query->day($day, $today)]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\AdminReportQuery;
use App\Support\Rooms\RoomOwner;
use Illuminate\Http\Request;
use Inertia\Response;

/** Owner-only booking performance overview and operational report. */
final class AdminReportController extends Controller
{
    public function __invoke(Request $request, AdminReportQuery $query): Response
    {
        (new RoomOwner)->authorize($request->user());

        $period = is_string($request->query('period')) ? $request->query('period') : null;

        return inertia('admin/Reports', [
            'report' => $query->get($period),
            'catalog' => ['packages' => []],
        ]);
    }
}

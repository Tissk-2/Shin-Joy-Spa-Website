<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Staff\SetBookingPermission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetBookingPermissionRequest;
use App\Models\User;
use App\Queries\TelegramStaffQuery;
use App\Support\AdminStaffPresenter;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffSchemaException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

final class AdminStaffController extends Controller
{
    private const PER_PAGE = 20;

    /** The staff-list page that shows this user, matching index()'s order and page size. */
    public static function pageOf(int $userId): int
    {
        $position = User::whereIn('role', [UserRole::Owner, UserRole::Admin])->where('id', '<=', $userId)->count();

        return intdiv(max($position, 1) - 1, self::PER_PAGE) + 1;
    }

    public function index(Request $request, AdminStaffPresenter $presenter): Response
    {
        (new RoomOwner)->authorize($request->user());
        $page = User::whereIn('role', [UserRole::Owner, UserRole::Admin])->orderBy('id')
            ->paginate(self::PER_PAGE, ['id', 'name', 'email', 'role', 'email_verified_at', 'can_manage_bookings']);
        $telegram = new TelegramStaffQuery;
        $states = $telegram->states($page->getCollection()->modelKeys(), CarbonImmutable::now('UTC'));
        $staff = $page->through(fn (User $user) => [...$presenter->summary($user), 'telegram' => $states[$user->id]]);
        $fresh = $request->session()->get('telegramEnrollment');

        return inertia('admin/staff/Index', ['staff' => $staff, 'catalog' => ['packages' => []],
            'telegramHistory' => $telegram->history(),
            'telegramBotConfigured' => TelegramStaffQuery::botUsername() !== null,
            'freshTelegramLink' => is_array($fresh) ? $fresh : null]);
    }

    public function update(SetBookingPermissionRequest $request, User $user, SetBookingPermission $set): RedirectResponse
    {
        (new RoomOwner)->authorize($request->user());
        try {
            $set($request->user(), (int) $user->id, (bool) $request->validated('allowed'));
        } catch (StaffSchemaException) {
            throw ValidationException::withMessages(['allowed' => __('admin.staff.unavailable')]);
        }

        return to_route('admin.staff.index', [], 303)->with('status', __('admin.staff.updated'));
    }
}

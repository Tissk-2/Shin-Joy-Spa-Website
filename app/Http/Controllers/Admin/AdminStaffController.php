<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Staff\SetBookingPermission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetBookingPermissionRequest;
use App\Models\User;
use App\Support\AdminStaffPresenter;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffSchemaException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

final class AdminStaffController extends Controller
{
    public function index(Request $request, AdminStaffPresenter $presenter): Response
    {
        (new RoomOwner)->authorize($request->user());
        $staff = User::whereIn('role', [UserRole::Owner, UserRole::Admin])->orderBy('id')
            ->paginate(20, ['id', 'name', 'email', 'role', 'email_verified_at', 'can_manage_bookings'])
            ->through($presenter->summary(...));

        return inertia('admin/staff/Index', ['staff' => $staff, 'catalog' => ['packages' => []]]);
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

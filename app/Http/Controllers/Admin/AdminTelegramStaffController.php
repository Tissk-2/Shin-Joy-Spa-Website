<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Telegram\ActivateStaffLink;
use App\Actions\Telegram\IssueStaffEnrollment;
use App\Actions\Telegram\RevokeStaffLink;
use App\Models\TelegramEnrollment;
use App\Queries\TelegramStaffQuery;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffSchemaException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminTelegramStaffController
{
    public function issue(Request $request, string $user, IssueStaffEnrollment $issue): RedirectResponse
    {
        (new RoomOwner)->authorize($request->user());

        try {
            $token = $issue($request->user(), (int) $user);
        } catch (StaffSchemaException) {
            throw ValidationException::withMessages(['staff' => __('admin.staff.unavailable')]);
        }

        $username = TelegramStaffQuery::botUsername();
        $expiresAt = TelegramEnrollment::query()->where('token_hash', hash('sha256', $token))->value('expires_at');

        return $this->backTo((int) $user)
            ->with('status', __('admin.telegram.issued'))
            ->with('telegramEnrollment', [
                'staffId' => (int) $user,
                'command' => '/start '.$token,
                'url' => $username !== null ? 'https://t.me/'.$username.'?start='.$token : null,
                'expiresAt' => CarbonImmutable::parse($expiresAt, 'UTC')->toIso8601ZuluString(),
            ]);
    }

    public function activate(Request $request, string $enrollment, ActivateStaffLink $activate): RedirectResponse
    {
        (new RoomOwner)->authorize($request->user());

        try {
            $link = $activate($request->user(), (int) $enrollment);
        } catch (StaffSchemaException) {
            throw ValidationException::withMessages(['staff' => __('admin.staff.unavailable')]);
        }

        return $this->backTo($link->user_id)->with('status', __('admin.telegram.activated'));
    }

    public function revoke(Request $request, string $user, RevokeStaffLink $revoke): RedirectResponse
    {
        (new RoomOwner)->authorize($request->user());

        try {
            $removed = $revoke($request->user(), (int) $user);
        } catch (StaffSchemaException) {
            throw ValidationException::withMessages(['staff' => __('admin.staff.unavailable')]);
        }

        return $this->backTo((int) $user)
            ->with('status', __($removed ? 'admin.telegram.revoked' : 'admin.telegram.cancelled'));
    }

    // The one-time link is flashed once, so land on the page whose row can show it.
    private function backTo(int $userId): RedirectResponse
    {
        $page = AdminStaffController::pageOf($userId);

        return to_route('admin.staff.index', $page > 1 ? ['page' => $page] : [], 303);
    }
}

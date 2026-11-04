<?php

namespace App\Http\Requests\Admin;

use App\Support\Rooms\RoomOwner;
use Illuminate\Foundation\Http\FormRequest;

final class SetBookingPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (new RoomOwner)->current($this->user()) !== null;
    }

    public function rules(): array
    {
        return ['allowed' => ['required', 'boolean']];
    }
}

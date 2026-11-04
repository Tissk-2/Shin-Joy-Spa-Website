<?php

namespace App\Http\Requests;

use App\Support\Scheduling\BookingAvailabilityInput;
use Illuminate\Foundation\Http\FormRequest;

final class BookingAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function selection(): array
    {
        return (new BookingAvailabilityInput)->validate($this->all());
    }
}

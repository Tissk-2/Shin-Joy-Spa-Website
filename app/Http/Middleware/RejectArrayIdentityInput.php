<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class RejectArrayIdentityInput
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fortify lowercases the email before validating registration and reset
        // input, so an array would 500 there instead of returning field errors.
        $errors = [];

        foreach (['name', 'email', 'phone', 'password'] as $field) {
            if (is_array($request->input($field))) {
                $errors[$field] = [trans('validation.string', [
                    'attribute' => trans('validation.attributes.'.$field),
                ])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $next($request);
    }
}

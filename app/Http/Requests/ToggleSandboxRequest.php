<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Authorization is done in the controller through the policy. */
class ToggleSandboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sandbox_enabled' => ['required', 'boolean'],
        ];
    }
}

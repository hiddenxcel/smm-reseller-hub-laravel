<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:190',
                Rule::unique(Tenant::class)->ignore($this->user()->id),
            ],
            // Optional, and only digits and the usual separators: it is a
            // contact number, shown to the platform's staff, not a login.
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\s-]*$/'],
        ];
    }
}

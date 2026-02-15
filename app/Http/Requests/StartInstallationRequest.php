<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'server_id'   => ['required', 'exists:servers,id'],
            'domain'      => ['required', 'string', 'max:253', 'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            'site_title'  => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.regex'      => 'Please enter a valid domain name (e.g., example.com).',
            'server_id.exists'  => 'The selected server does not exist.',
        ];
    }
}

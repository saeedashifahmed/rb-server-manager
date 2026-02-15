<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'ip_address'      => ['required', 'ip'],
            'ssh_port'        => ['required', 'integer', 'min:1', 'max:65535'],
            'ssh_username'    => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            'ssh_private_key' => ['required', 'string', 'min:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'ip_address.ip'          => 'Please enter a valid IP address.',
            'ssh_port.min'           => 'SSH port must be between 1 and 65535.',
            'ssh_port.max'           => 'SSH port must be between 1 and 65535.',
            'ssh_username.regex'     => 'SSH username may only contain letters, numbers, hyphens, and underscores.',
            'ssh_private_key.min'    => 'The SSH private key appears to be invalid.',
        ];
    }
}

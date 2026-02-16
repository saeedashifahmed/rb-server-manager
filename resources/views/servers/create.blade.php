@extends('layouts.app')

@section('header')
    <div>
        <a href="{{ route('servers.index') }}" class="text-sm text-brand-600 hover:text-brand-500">&larr; Back to Servers</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">Add Server</h1>
        <p class="mt-1 text-sm text-gray-500">Connect a new VPS server via SSH.</p>
    </div>
@endsection

@section('content')
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('servers.store') }}" class="bg-white rounded-xl border border-gray-200 p-6 space-y-6">
            @csrf

            <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                <p class="text-sm text-blue-800">
                    Provide at least one authentication method: <strong>SSH Private Key</strong> or <strong>SSH Password</strong>.
                </p>
            </div>

            {{-- Server Name --}}
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Server Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                       placeholder="e.g., Production VPS"
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- IP Address --}}
            <div>
                <label for="ip_address" class="block text-sm font-medium text-gray-700 mb-1">IP Address</label>
                <input id="ip_address" name="ip_address" type="text" value="{{ old('ip_address') }}" required
                       placeholder="e.g., 203.0.113.10"
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                @error('ip_address')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                {{-- SSH Port --}}
                <div>
                    <label for="ssh_port" class="block text-sm font-medium text-gray-700 mb-1">SSH Port</label>
                    <input id="ssh_port" name="ssh_port" type="number" value="{{ old('ssh_port', 22) }}" required
                           min="1" max="65535"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                    @error('ssh_port')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- SSH Username --}}
                <div>
                    <label for="ssh_username" class="block text-sm font-medium text-gray-700 mb-1">SSH Username</label>
                    <input id="ssh_username" name="ssh_username" type="text" value="{{ old('ssh_username', 'root') }}" required
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                    @error('ssh_username')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- SSH Private Key --}}
            <div>
                <label for="ssh_private_key" class="block text-sm font-medium text-gray-700 mb-1">SSH Private Key (optional)</label>
                <textarea id="ssh_private_key" name="ssh_private_key" rows="8"
                          placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"
                          class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border font-mono text-xs">{{ old('ssh_private_key') }}</textarea>
                <p class="mt-1 text-xs text-gray-500">Paste your full SSH private key here. It will be encrypted before storage.</p>
                @error('ssh_private_key')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- SSH Password --}}
            <div>
                <label for="ssh_password" class="block text-sm font-medium text-gray-700 mb-1">SSH Password (optional)</label>
                <input id="ssh_password" name="ssh_password" type="password" value="{{ old('ssh_password') }}"
                       placeholder="Root or SSH user password"
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                <p class="mt-1 text-xs text-gray-500">Use this if your server uses password-based SSH login. It will be encrypted before storage.</p>
                @error('ssh_password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Submit --}}
            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-100">
                <a href="{{ route('servers.index') }}"
                   class="px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="submit"
                        class="px-6 py-2.5 text-sm font-semibold text-white bg-brand-600 rounded-lg hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 transition">
                    Add Server
                </button>
            </div>
        </form>
    </div>
@endsection

@extends('layouts.app')

@section('header')
    <div>
        <a href="{{ route('installations.index') }}" class="text-sm text-brand-600 hover:text-brand-500">&larr; Back to Installations</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">Install WordPress + SSL</h1>
        <p class="mt-1 text-sm text-gray-500">Deploy WordPress with Let's Encrypt SSL on your server.</p>
    </div>
@endsection

@section('content')
    @if($servers->isEmpty())
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center max-w-2xl">
            <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900">No servers available</h3>
            <p class="mt-2 text-sm text-gray-500">You need to add a server before installing WordPress.</p>
            <a href="{{ route('servers.create') }}" class="mt-4 inline-flex items-center text-sm text-brand-600 hover:text-brand-500 font-medium">
                Add a server first &rarr;
            </a>
        </div>
    @else
        <div class="max-w-2xl">
            <form method="POST" action="{{ route('installations.store') }}" class="bg-white rounded-xl border border-gray-200 p-6 space-y-6">
                @csrf

                {{-- Server Selection --}}
                <div>
                    <label for="server_id" class="block text-sm font-medium text-gray-700 mb-1">Select Server</label>
                    <select id="server_id" name="server_id" required
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                        <option value="">Choose a server...</option>
                        @foreach($servers as $server)
                            <option value="{{ $server->id }}"
                                    {{ old('server_id', request('server_id')) == $server->id ? 'selected' : '' }}>
                                {{ $server->name }} ({{ $server->ip_address }})
                            </option>
                        @endforeach
                    </select>
                    @error('server_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Domain --}}
                <div>
                    <label for="domain" class="block text-sm font-medium text-gray-700 mb-1">Domain Name</label>
                    <input id="domain" name="domain" type="text" value="{{ old('domain') }}" required
                           placeholder="e.g., example.com"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                    <p class="mt-1 text-xs text-gray-500">Make sure the domain's DNS A record points to your server's IP address.</p>
                    @error('domain')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Admin Email --}}
                <div>
                    <label for="admin_email" class="block text-sm font-medium text-gray-700 mb-1">Admin Email</label>
                    <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email', Auth::user()->email) }}" required
                           placeholder="admin@example.com"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                    <p class="mt-1 text-xs text-gray-500">Used for Let's Encrypt SSL certificate registration.</p>
                    @error('admin_email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Site Title --}}
                <div>
                    <label for="site_title" class="block text-sm font-medium text-gray-700 mb-1">Site Title (optional)</label>
                    <input id="site_title" name="site_title" type="text" value="{{ old('site_title', 'My WordPress Site') }}"
                           placeholder="My WordPress Site"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                    @error('site_title')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- PHP Version --}}
                <div>
                    <label for="php_version" class="block text-sm font-medium text-gray-700 mb-1">PHP Version</label>
                    <select id="php_version" name="php_version"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 px-4 py-2.5 border">
                        @foreach(\App\Services\ScriptBuilder::SUPPORTED_PHP_VERSIONS as $version)
                            <option value="{{ $version }}"
                                    {{ old('php_version', \App\Services\ScriptBuilder::DEFAULT_PHP_VERSION) === $version ? 'selected' : '' }}>
                                PHP {{ $version }}{{ $version === \App\Services\ScriptBuilder::DEFAULT_PHP_VERSION ? ' (recommended)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Select the PHP version for your WordPress installation. PHP {{ \App\Services\ScriptBuilder::DEFAULT_PHP_VERSION }} is recommended for the best compatibility.</p>
                    @error('php_version')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Info Box --}}
                <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                    <div class="flex">
                        <svg class="h-5 w-5 text-blue-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800">What will be installed</h3>
                            <ul class="mt-2 text-sm text-blue-700 list-disc list-inside space-y-1">
                                <li>Nginx web server</li>
                                <li>MySQL / MariaDB database server</li>
                                <li>PHP with all WordPress-required extensions</li>
                                <li>Latest WordPress from wordpress.org</li>
                                <li>Let's Encrypt SSL certificate with auto-renewal</li>
                                <li>Security-hardened wp-config.php</li>
                                <li>Optimized PHP-FPM &amp; Nginx configuration</li>
                            </ul>
                            <p class="mt-3 text-xs text-blue-600">
                                <strong>Supported OS:</strong> Ubuntu 20.04, 22.04, 24.04 &amp; Debian 11, 12
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-100">
                    <a href="{{ route('installations.index') }}"
                       class="px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                        Cancel
                    </a>
                    <button type="submit"
                            class="px-6 py-2.5 text-sm font-semibold text-white bg-green-600 rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition">
                        Install WordPress + SSL
                    </button>
                </div>
            </form>
        </div>
    @endif
@endsection

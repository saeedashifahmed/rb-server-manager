@extends('layouts.app')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('servers.index') }}" class="text-sm text-brand-600 hover:text-brand-500">&larr; Back to Servers</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ $server->name }}</h1>
            <div class="flex items-center space-x-3 mt-1">
                <code class="bg-gray-100 px-2 py-0.5 rounded text-sm text-gray-700">{{ $server->ip_address }}</code>
                <span class="text-sm text-gray-500">Port: {{ $server->ssh_port }}</span>
                <span class="text-sm text-gray-500">User: {{ $server->ssh_username }}</span>
            </div>
        </div>
        <div class="flex items-center space-x-2">
            <form method="POST" action="{{ route('servers.test', $server) }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                    Test SSH Connection
                </button>
            </form>
            <a href="{{ route('installations.create', ['server_id' => $server->id]) }}"
               class="inline-flex items-center px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition">
                Install WordPress + SSL
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Installations on this server</h2>
        </div>

        @if($installations->isEmpty())
            <div class="p-12 text-center">
                <p class="text-sm text-gray-500">No WordPress installations on this server yet.</p>
                <a href="{{ route('installations.create', ['server_id' => $server->id]) }}"
                   class="mt-2 inline-flex text-sm text-brand-600 hover:text-brand-500 font-medium">
                    Install WordPress + SSL &rarr;
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Domain</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">WP Admin</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($installations as $installation)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $installation->domain }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @include('partials.status-badge', ['status' => $installation->status])
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                            <div class="bg-brand-600 h-2 rounded-full" style="width: {{ $installation->progress }}%"></div>
                                        </div>
                                        <span class="text-xs text-gray-500">{{ $installation->progress }}%</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if($installation->wp_admin_url)
                                        <a href="{{ $installation->wp_admin_url }}" target="_blank"
                                           class="text-brand-600 hover:text-brand-500 font-medium">
                                            Open &nearr;
                                        </a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $installation->created_at->diffForHumans() }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <a href="{{ route('installations.show', $installation) }}" class="text-brand-600 hover:text-brand-900 font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-6 py-3 border-t border-gray-200">
                {{ $installations->links() }}
            </div>
        @endif
    </div>
@endsection

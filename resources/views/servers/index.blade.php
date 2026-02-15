@extends('layouts.app')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Servers</h1>
            <p class="mt-1 text-sm text-gray-500">Manage your connected VPS servers.</p>
        </div>
        <a href="{{ route('servers.create') }}"
           class="inline-flex items-center px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition">
            <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Server
        </a>
    </div>
@endsection

@section('content')
    @if($servers->isEmpty())
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900">No servers added yet</h3>
            <p class="mt-2 text-sm text-gray-500">Add your first VPS server to get started.</p>
            <a href="{{ route('servers.create') }}"
               class="mt-4 inline-flex items-center text-sm text-brand-600 hover:text-brand-500 font-medium">
                Add your first server &rarr;
            </a>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($servers as $server)
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="flex-shrink-0 p-3 bg-gray-50 rounded-lg">
                                <svg class="h-6 w-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">
                                    <a href="{{ route('servers.show', $server) }}" class="hover:text-brand-600 transition">
                                        {{ $server->name }}
                                    </a>
                                </h3>
                                <div class="flex items-center space-x-3 mt-1">
                                    <span class="text-sm text-gray-500">
                                        <code class="bg-gray-100 px-2 py-0.5 rounded text-xs">{{ $server->ip_address }}</code>
                                    </span>
                                    <span class="text-sm text-gray-400">|</span>
                                    <span class="text-sm text-gray-500">Port: {{ $server->ssh_port }}</span>
                                    <span class="text-sm text-gray-400">|</span>
                                    <span class="text-sm text-gray-500">User: {{ $server->ssh_username }}</span>
                                    <span class="text-sm text-gray-400">|</span>
                                    <span class="text-sm text-gray-500">{{ $server->installations_count }} installation(s)</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center space-x-2">
                            <form method="POST" action="{{ route('servers.test', $server) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                                    Test SSH
                                </button>
                            </form>
                            <a href="{{ route('servers.show', $server) }}"
                               class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                                View
                            </a>
                            <form method="POST" action="{{ route('servers.destroy', $server) }}"
                                  onsubmit="return confirm('Are you sure you want to delete this server? This will also delete all associated installations.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-red-300 text-red-700 hover:bg-red-50 transition">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $servers->links() }}
        </div>
    @endif
@endsection

@extends('layouts.app')

@section('header')
    <div>
        <a href="{{ route('installations.index') }}" class="text-sm text-brand-600 hover:text-brand-500">&larr; Back to Installations</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">Installation: {{ $installation->domain }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            Server: {{ $installation->server->name }} ({{ $installation->server->ip_address }})
        </p>
    </div>
@endsection

@section('content')
<div x-data="installationProgress()" x-init="init()">

    {{-- Status Card --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-3">
                <h2 class="text-lg font-semibold text-gray-900">Installation Status</h2>
                <span x-html="statusBadge"></span>
            </div>
            <div class="text-sm text-gray-500" x-show="startedAt">
                Started: <span x-text="startedAt"></span>
            </div>
        </div>

        {{-- Progress Bar --}}
        <div class="mb-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-700" x-text="currentStep || 'Waiting to start...'"></span>
                <span class="text-sm font-semibold text-brand-600" x-text="progress + '%'"></span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3">
                <div class="h-3 rounded-full transition-all duration-700 ease-out"
                     :class="{
                         'bg-brand-600': status === 'installing' || status === 'pending',
                         'bg-green-500': status === 'success',
                         'bg-red-500': status === 'failed'
                     }"
                     :style="'width: ' + progress + '%'"></div>
            </div>
        </div>

        {{-- Step Indicators --}}
        <div class="grid grid-cols-4 sm:grid-cols-8 gap-2 mt-4">
            <template x-for="step in steps" :key="step.name">
                <div class="text-center">
                    <div class="mx-auto w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition"
                         :class="{
                             'bg-green-100 text-green-700': step.progress <= progress && progress > 0,
                             'bg-blue-100 text-blue-700 animate-pulse': step.active,
                             'bg-gray-100 text-gray-400': step.progress > progress
                         }">
                        <span x-show="step.progress <= progress && progress > 0">&#10003;</span>
                        <span x-show="step.progress > progress" x-text="step.num"></span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 leading-tight" x-text="step.short"></p>
                </div>
            </template>
        </div>
    </div>

    {{-- Success Card --}}
    <div x-show="status === 'success'" x-cloak
         class="bg-green-50 border border-green-200 rounded-xl p-6 mb-6">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-8 w-8 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-semibold text-green-800">Installation Successful!</h3>
                <p class="mt-1 text-sm text-green-700">Your WordPress site is live with SSL.</p>
                <div class="mt-4 space-y-2">
                    <div class="flex items-center space-x-2">
                        <span class="text-sm font-medium text-green-800">WordPress Admin:</span>
                        <a :href="wpAdminUrl" target="_blank"
                           class="text-sm text-green-700 underline hover:text-green-600 font-medium"
                           x-text="wpAdminUrl"></a>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span class="text-sm font-medium text-green-800">Site URL:</span>
                        <a :href="'https://{{ $installation->domain }}'" target="_blank"
                           class="text-sm text-green-700 underline hover:text-green-600 font-medium">
                            https://{{ $installation->domain }}
                        </a>
                    </div>
                </div>
                <p class="mt-3 text-xs text-green-600">
                    Navigate to the WordPress admin URL to complete the setup wizard.
                </p>
            </div>
        </div>
    </div>

    {{-- Error Card --}}
    <div x-show="status === 'failed'" x-cloak
         class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-8 w-8 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-semibold text-red-800">Installation Failed</h3>
                <p class="mt-1 text-sm text-red-700" x-text="errorMessage"></p>
            </div>
        </div>
    </div>

    {{-- Installation Details --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Domain</p>
            <p class="text-sm font-semibold text-gray-900">{{ $installation->domain }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">PHP Version</p>
            <p class="text-sm font-semibold text-gray-900">{{ $installation->php_version ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Database</p>
            <p class="text-sm font-semibold text-gray-900">{{ $installation->wp_db_name ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">DB User</p>
            <p class="text-sm font-semibold text-gray-900">{{ $installation->wp_db_user ?? '—' }}</p>
        </div>
    </div>

    {{-- Log Output --}}
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Installation Log</h2>
            <button @click="showLog = !showLog"
                    class="text-sm text-brand-600 hover:text-brand-500 font-medium"
                    x-text="showLog ? 'Hide Log' : 'Show Log'"></button>
        </div>
        <div x-show="showLog" x-cloak class="p-4">
            <pre class="bg-gray-900 text-green-400 rounded-lg p-4 overflow-x-auto text-xs font-mono leading-relaxed max-h-96 overflow-y-auto"
                 x-text="log || 'Waiting for output...'"></pre>
        </div>
    </div>
</div>

<script>
function installationProgress() {
    return {
        status: '{{ $installation->status }}',
        currentStep: '{{ $installation->current_step ?? '' }}',
        progress: {{ $installation->progress }},
        log: @json($installation->log ?? ''),
        wpAdminUrl: '{{ $installation->wp_admin_url ?? '' }}',
        errorMessage: '{{ $installation->error_message ?? '' }}',
        startedAt: '{{ $installation->started_at?->diffForHumans() ?? '' }}',
        showLog: false,
        polling: null,
        steps: [
            { num: 1, short: 'Preflight', progress: 2 },
            { num: 2, short: 'System', progress: 8 },
            { num: 3, short: 'Nginx', progress: 15 },
            { num: 4, short: 'MySQL', progress: 25 },
            { num: 5, short: 'PHP', progress: 38 },
            { num: 6, short: 'Secure DB', progress: 42 },
            { num: 7, short: 'Create DB', progress: 48 },
            { num: 8, short: 'WordPress', progress: 56 },
            { num: 9, short: 'Config', progress: 62 },
            { num: 10, short: 'Perms', progress: 68 },
            { num: 11, short: 'Nginx Cfg', progress: 74 },
            { num: 12, short: 'Restart', progress: 78 },
            { num: 13, short: 'Certbot', progress: 84 },
            { num: 14, short: 'SSL', progress: 92 },
            { num: 15, short: 'Auto-Renew', progress: 96 },
            { num: 16, short: 'Verify', progress: 99 },
        ],

        get statusBadge() {
            const colors = {
                pending: 'bg-yellow-100 text-yellow-800',
                installing: 'bg-blue-100 text-blue-800',
                success: 'bg-green-100 text-green-800',
                failed: 'bg-red-100 text-red-800',
            };
            const cls = colors[this.status] || 'bg-gray-100 text-gray-800';
            const spin = this.status === 'installing' ?
                '<svg class="mr-1 h-3 w-3 animate-spin inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>' : '';
            return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${cls}">${spin}${this.status.charAt(0).toUpperCase() + this.status.slice(1)}</span>`;
        },

        init() {
            if (this.status === 'pending' || this.status === 'installing') {
                this.showLog = true;
                this.startPolling();
            }
        },

        startPolling() {
            this.polling = setInterval(() => this.fetchStatus(), 3000);
        },

        async fetchStatus() {
            try {
                const res = await fetch('{{ route("installations.status", $installation) }}');
                const data = await res.json();

                this.status = data.status;
                this.currentStep = data.current_step || '';
                this.progress = data.progress;
                this.log = data.log || '';
                this.wpAdminUrl = data.wp_admin_url || '';
                this.errorMessage = data.error_message || '';
                this.startedAt = data.started_at || '';

                if (data.status === 'success' || data.status === 'failed') {
                    clearInterval(this.polling);
                }
            } catch (e) {
                console.error('Failed to fetch status:', e);
            }
        }
    };
}
</script>
@endsection

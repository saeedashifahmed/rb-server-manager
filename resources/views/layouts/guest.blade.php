@extends('layouts.base')

@section('body')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-brand-50 to-blue-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <svg class="mx-auto h-12 w-12 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
            <h1 class="mt-3 text-2xl font-bold text-gray-900">{{ config('app.name') }}</h1>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-8">
            @yield('form')
        </div>
    </div>
</div>
@endsection

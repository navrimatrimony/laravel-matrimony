@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'manual';
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Create Profile</h1>
        <p class="mt-1 text-sm text-gray-600">Create the member account, then continue in the existing Edit all form with all and section-wise navigation.</p>
    </div>

    @include('admin.intake._tabs')

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please fix the following:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.biodata-intakes.store-profile') }}" class="space-y-6 rounded-xl bg-white p-6 shadow">
        @csrf

        @include('admin.intake._registration-fields', [
            'registrationHint' => 'Mobile and email are checked before registration. Profile fields are saved through the existing governed Edit all form.',
        ])

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            Continue opens the existing full wizard. The same page also provides every section separately; no second profile form is maintained.
        </div>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">
                Continue to Edit all
            </button>
        </div>
    </form>
</div>
@endsection

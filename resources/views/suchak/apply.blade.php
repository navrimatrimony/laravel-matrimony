@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8">
    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Account Request</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                Submit your Suchak business identity for admin verification.
            </p>
        </div>

        <form method="POST" action="{{ route('suchak.apply.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="suchak_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Suchak name <span class="text-red-600">*</span></label>
                <input id="suchak_name" name="suchak_name" type="text" required maxlength="255" value="{{ old('suchak_name', auth()->user()->name) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @error('suchak_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="office_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Office name</label>
                <input id="office_name" name="office_name" type="text" maxlength="255" value="{{ old('office_name') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @error('office_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="business_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Business type <span class="text-red-600">*</span></label>
                <select id="business_type" name="business_type" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ([
                        \App\Models\SuchakAccount::BUSINESS_TYPE_INDIVIDUAL => 'Individual',
                        \App\Models\SuchakAccount::BUSINESS_TYPE_BUREAU => 'Bureau',
                        \App\Models\SuchakAccount::BUSINESS_TYPE_ORGANIZATION => 'Organization',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(old('business_type', \App\Models\SuchakAccount::BUSINESS_TYPE_INDIVIDUAL) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('business_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="mobile_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Mobile number</label>
                    <input id="mobile_number" name="mobile_number" type="text" maxlength="20" value="{{ old('mobile_number', auth()->user()->mobile) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @error('mobile_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">WhatsApp number</label>
                    <input id="whatsapp_number" name="whatsapp_number" type="text" maxlength="20" value="{{ old('whatsapp_number') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @error('whatsapp_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Business email</label>
                <input id="email" name="email" type="email" maxlength="255" value="{{ old('email', auth()->user()->email) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="address_line" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                <textarea id="address_line" name="address_line" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('address_line') }}</textarea>
                @error('address_line') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                <a href="{{ route('dashboard') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</a>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Submit request</button>
            </div>
        </form>
    </section>
</div>
@endsection

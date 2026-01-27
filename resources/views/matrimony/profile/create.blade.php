@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Matrimony Profile Create
                </h1>

                <form method="POST" action="{{ route('matrimony.profile.store') }}">
    @csrf

                    {{-- Day-18: Only show enabled and visible fields --}}
                    @php
                        $visibleFields = $visibleFields ?? [];
                        $enabledFields = $enabledFields ?? [];
                        $isVisible = fn($fieldKey) => in_array($fieldKey, $visibleFields, true);
                        $isEnabled = fn($fieldKey) => in_array($fieldKey, $enabledFields, true);
                    @endphp

                    <label>Full Name</label><br>
                    <input type="text" name="full_name"><br><br>

                    @if ($isEnabled('date_of_birth') && $isVisible('date_of_birth'))
                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth"><br><br>
                    @endif

                    @if ($isEnabled('marital_status') && $isVisible('marital_status'))
                    <label>Marital Status</label><br>
                    <select name="marital_status" required>
                        <option value="">— Select —</option>
                        <option value="single" {{ old('marital_status') === 'single' ? 'selected' : '' }}>Single</option>
                        <option value="divorced" {{ old('marital_status') === 'divorced' ? 'selected' : '' }}>Divorced</option>
                        <option value="widowed" {{ old('marital_status') === 'widowed' ? 'selected' : '' }}>Widowed</option>
                    </select><br><br>
                    @endif

                    @if ($isEnabled('education') && $isVisible('education'))
                    <label>Education</label><br>
                    <input type="text" name="education"><br><br>
                    @endif

                    @if ($isEnabled('caste') && $isVisible('caste'))
                    <label>Caste</label><br>
                    <input type="text" name="caste"><br><br>
                    @endif

                    @if ($isEnabled('location') && $isVisible('location'))
                    <label>Location</label><br>
                    <input type="text" name="location"><br><br>
                    @endif

                    <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; margin-top: 20px;">
    Save Profile
</button>


                    
                </form>

            </div>
        </div>
    </div>
</div>

@endsection

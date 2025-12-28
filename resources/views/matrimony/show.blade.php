@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        Matrimony Profile
    </h1>

    <div class="bg-white shadow rounded p-6 space-y-3">
        <p><strong>Full Name:</strong> {{ $profile->full_name }}</p>
        <p><strong>Gender:</strong> {{ $profile->gender }}</p>
        <p><strong>Date of Birth:</strong> {{ $profile->date_of_birth }}</p>
        <p><strong>Education:</strong> {{ $profile->education }}</p>
        <p><strong>Location:</strong> {{ $profile->location }}</p>
    </div>
</div>
@endsection

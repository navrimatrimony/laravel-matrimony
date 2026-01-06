@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Upload Profile Photo
                </h1>

                <form method="POST"
                      action="{{ route('matrimony.profile.store-photo') }}"
                      enctype="multipart/form-data">
                    @csrf

                    <label>Profile Photo</label><br>
                    <input type="file" name="profile_photo" required><br><br>

                    <button type="submit">
                        Upload Photo
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection

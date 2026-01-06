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


                    <label>Full Name</label><br>
                    <input type="text" name="full_name"><br><br>

                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth"><br><br>

                    <label>Education</label><br>
                    <input type="text" name="education"><br><br>

                    <label>Caste</label><br>
                    <input type="text" name="caste"><br><br>

                    <label>Location</label><br>
                    <input type="text" name="location"><br><br>

                    

                    <button type="submit">
                        Save Profile
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection

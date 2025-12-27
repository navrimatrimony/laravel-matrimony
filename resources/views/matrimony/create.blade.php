<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Create Matrimony Profile
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">

                <form method="POST" action="{{ route('matrimony.profile.store') }}">

                    @csrf

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">
                            Full Name
                        </label>
                        <input type="text" name="full_name"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">
                            Date of Birth
                        </label>
                        <input type="date" name="date_of_birth"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">
                            Education
                        </label>
                        <input type="text" name="education"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">
                            Location
                        </label>
                        <input type="text" name="location"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded">
                        Save Profile
                    </button>

                </form>

            </div>
        </div>
    </div>
</x-app-layout>

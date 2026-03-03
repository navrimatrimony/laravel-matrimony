@extends('layouts.app')

@section('content')
<div class="container max-w-2xl mx-auto py-8 px-4">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('intake.index') }}" class="hover:underline">← My biodata uploads</a>
    </p>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">Upload Biodata</h1>

    @if (session('success'))
        <p style="color: green;">{{ session('success') }}</p>
    @endif

    @if ($errors->any())
        <ul style="color: red;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('intake.store') }}" enctype="multipart/form-data">
        @csrf

        <div>
            <label for="raw_text">Raw biodata text</label><br>
            <textarea name="raw_text" id="raw_text" rows="6" placeholder="Paste raw biodata text here">{{ old('raw_text') }}</textarea>
        </div>

        <div>
            <label for="file">Or upload file</label><br>
            <input type="file" name="file" id="file" accept=".pdf,.jpg,.jpeg,.png,.txt">
        </div>

        <div>
            <button type="submit">Upload Intake</button>
        </div>
    </form>
</div>
@endsection

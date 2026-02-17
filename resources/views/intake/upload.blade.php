@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Upload Intake</h1>

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

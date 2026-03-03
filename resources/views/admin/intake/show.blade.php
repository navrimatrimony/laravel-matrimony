@extends('layouts.admin')

@section('content')
<div class="container">
    <h1>Admin Intake Page</h1>
    <p>Phase-5 Admin Intake Skeleton</p>

    <h4 class="mt-4">Raw OCR text <span class="text-sm font-normal text-gray-500">(what was extracted from PDF/image)</span></h4>
    <pre class="bg-gray-100 p-3 rounded overflow-auto max-h-96 text-sm whitespace-pre-wrap">{{ $intake->raw_ocr_text ?? '(empty)' }}</pre>

    <h4 class="mt-4">Parsed JSON</h4>
    <pre class="bg-gray-100 p-3 rounded overflow-auto">{{ json_encode($intake->parsed_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</div>
@endsection

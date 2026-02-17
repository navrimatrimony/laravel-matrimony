<?php

namespace App\Http\Controllers;

use App\Models\BiodataIntake;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| IntakeController — Phase-5 User-Side Intake UI Foundation
|--------------------------------------------------------------------------
|
| User-side intake flow: upload form, preview, approval, status.
|
*/
class IntakeController extends Controller
{
    /**
     * Show upload form.
     */
    public function uploadForm()
    {
        return view('intake.upload');
    }

    /**
     * Phase-5 Day-11 Step-1: Store biodata intake (upload base).
     * Creates biodata_intakes record. No lifecycle, parsing, approval, or mutation.
     */
    public function store(Request $request)
    {
        $request->validate([
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:10240', 'required_without:raw_text'],
        ]);

        $path = null;
        $originalName = null;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('intakes');
            $originalName = $request->file('file')->getClientOriginalName();
        }

        if ($request->filled('raw_text')) {
            $rawText = $request->input('raw_text');
        } else {
            $rawText = 'FILE_UPLOADED';
        }

        DB::transaction(function () use ($path, $originalName, $rawText) {
            BiodataIntake::create([
                'uploaded_by' => auth()->id(),
                'file_path' => $path,
                'original_filename' => $originalName,
                'raw_ocr_text' => $rawText,
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'approved_by_user' => false,
                'intake_locked' => false,
                'snapshot_schema_version' => 1,
            ]);
        });

        return redirect()->route('intake.status')->with('success', 'Intake uploaded successfully.');
    }

    /**
     * Show preview.
     */
    public function preview()
    {
        return view('intake.preview');
    }

    /**
     * Show approval.
     */
    public function approval()
    {
        return view('intake.approval');
    }

    /**
     * Show status.
     */
    public function status()
    {
        return view('intake.status');
    }
}

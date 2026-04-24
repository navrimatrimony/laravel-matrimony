<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\VerificationTag;
use App\Services\Admin\TagAssignmentService;
use App\Support\ErrorFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminProfileTagController extends Controller
{
    public function __construct(private TagAssignmentService $tagAssignmentService) {}

    public function assign(Request $request, MatrimonyProfile $profile)
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:1'],
            'tag_id' => ['required', 'integer', 'exists:verification_tags,id'],
        ]);

        $admin = $request->user();
        if (! $admin) {
            abort(403, 'Unauthorized.');
        }

        $tag = VerificationTag::withTrashed()->findOrFail($request->integer('tag_id'));

        try {
            $this->tagAssignmentService->assignTag($admin, $profile, $tag, $request->reason);

            return redirect()->back()->with('success', 'Tag assigned successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput()->with('error', ErrorFactory::adminTagAssignFailed()->message);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->with('error', ErrorFactory::generic()->message);
        }
    }

    public function remove(Request $request, MatrimonyProfile $profile, $tag)
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:1'],
        ]);

        $admin = $request->user();
        if (! $admin) {
            abort(403, 'Unauthorized.');
        }

        $tag = VerificationTag::withTrashed()->findOrFail($tag);

        try {
            $this->tagAssignmentService->removeTag($admin, $profile, $tag, $request->reason);

            return redirect()->back()->with('success', 'Tag removed successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput()->with('error', ErrorFactory::adminTagRemoveFailed()->message);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->with('error', ErrorFactory::generic()->message);
        }
    }
}

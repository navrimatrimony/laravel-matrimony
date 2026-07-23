<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakVerificationRecord;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dedicated Suchak onboarding photo triage queue (profile / office / logo).
 * Approvals still go through AccountVerificationController + lifecycle service.
 */
class PhotoReviewController extends Controller
{
    /**
     * @return list<string>
     */
    public static function photoTypes(): array
    {
        return [
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
        ];
    }

    public function index(Request $request): View
    {
        $photoTypes = self::photoTypes();
        $allowedStatuses = [
            SuchakVerificationRecord::STATUS_PENDING,
            SuchakVerificationRecord::STATUS_APPROVED,
            SuchakVerificationRecord::STATUS_REJECTED,
        ];

        $status = $request->query('admin_status', SuchakVerificationRecord::STATUS_PENDING);
        $status = in_array($status, $allowedStatuses, true) ? $status : SuchakVerificationRecord::STATUS_PENDING;

        $type = $request->query('verification_type');
        $type = in_array($type, $photoTypes, true) ? $type : null;

        $records = SuchakVerificationRecord::query()
            ->with(['suchakAccount.user', 'adminUser'])
            ->whereIn('verification_type', $photoTypes)
            ->whereNotNull('document_path')
            ->where('document_path', '!=', '')
            ->when($status, fn ($query) => $query->where('admin_status', $status))
            ->when($type, fn ($query) => $query->where('verification_type', $type))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $pendingCount = SuchakVerificationRecord::query()
            ->whereIn('verification_type', $photoTypes)
            ->where('admin_status', SuchakVerificationRecord::STATUS_PENDING)
            ->whereNotNull('document_path')
            ->where('document_path', '!=', '')
            ->count();

        return view('admin.suchak.photo-reviews.index', [
            'records' => $records,
            'photoTypes' => $photoTypes,
            'allowedStatuses' => $allowedStatuses,
            'status' => $status,
            'type' => $type,
            'pendingCount' => $pendingCount,
        ]);
    }
}

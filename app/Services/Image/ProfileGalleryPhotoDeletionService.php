<?php

namespace App\Services\Image;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use Illuminate\Support\Facades\DB;
/**
 * Shared gallery delete + primary promotion (same behaviour as member delete / admin queue).
 */
class ProfileGalleryPhotoDeletionService
{
    public function deleteWithPrimaryResync(MatrimonyProfile $profile, ProfilePhoto $photo): void
    {
        $fileToDeleteLegacy = public_path('uploads/matrimony_photos/'.$photo->file_path);
        $fileToDeleteNew = storage_path('app/public/matrimony_photos/'.$photo->file_path);

        $wasPrimary = (bool) $photo->is_primary;

        $priorBypass = MatrimonyProfile::$bypassGovernanceEnforcement;
        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            DB::transaction(function () use ($profile, $photo, $wasPrimary): void {
                $photo->delete();

                $remaining = ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id']);

                foreach ($remaining as $idx => $row) {
                    ProfilePhoto::query()
                        ->where('profile_id', $profile->id)
                        ->where('id', (int) $row->id)
                        ->update(['sort_order' => (int) $idx]);
                }

                if (! $wasPrimary) {
                    return;
                }

                if ($remaining->count() === 0) {
                    $profile->profile_photo = null;
                    $profile->photo_approved = false;
                    $profile->photo_rejected_at = null;
                    $profile->photo_rejection_reason = null;
                    $profile->save();

                    return;
                }

                $replacement = ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->effectivelyApproved()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if (! $replacement) {
                    $replacement = ProfilePhoto::query()
                        ->where('profile_id', $profile->id)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();
                }

                if (! $replacement) {
                    return;
                }

                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->update(['is_primary' => false]);

                $replacement->is_primary = true;
                $replacement->save();

                $replacementApproved = $replacement->effectiveApprovedStatus() === 'approved';
                $profile->profile_photo = $replacement->file_path;
                $profile->photo_approved = $replacementApproved;
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
                $profile->save();
            });
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
        }

        foreach ([$fileToDeleteNew, $fileToDeleteLegacy] as $fileToDelete) {
            if (is_string($fileToDelete) && $fileToDelete !== '' && is_file($fileToDelete)) {
                @unlink($fileToDelete);
            }
        }
    }
}

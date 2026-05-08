<?php

namespace App\Services\DataAudit;

use Illuminate\Support\Facades\File;

class SnapshotStorageService
{
    private function basePath(): string
    {
        return (string) config('data-governance.platform.storage.snapshot_base_path', storage_path('app/data-audit/snapshots'));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function storeProfileSnapshot(int $profileId, array $snapshot): string
    {
        // Canonical entity naming for matrimony snapshots.
        return $this->storeEntitySnapshot('matrimony_profile', (string) $profileId, $snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function storeEntitySnapshot(string $entityType, string $entityId, array $snapshot): string
    {
        $this->normalizeAllLegacyProfileFolders();
        $this->normalizeLegacyEntityFolders($entityType, $entityId);
        $dir = $this->basePath().'/'.$entityType.'_'.$entityId;
        File::ensureDirectoryExists($dir);

        $ts = now()->format('Y_m_d_His');
        $file = $dir.'/snapshot_'.$ts.'.json';

        File::put(
            $file,
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $file;
    }

    private function normalizeLegacyEntityFolders(string $entityType, string $entityId): void
    {
        $base = $this->basePath();
        $canonical = $base.'/'.$entityType.'_'.$entityId;
        $legacy = $base.'/profile_'.$entityId;
        if (! is_dir($legacy)) {
            return;
        }

        File::ensureDirectoryExists($canonical);
        foreach (File::files($legacy) as $file) {
            $target = $canonical.'/'.$file->getFilename();
            if (! file_exists($target)) {
                @rename($file->getPathname(), $target);
            }
        }
        $remaining = File::files($legacy);
        if ($remaining === []) {
            @rmdir($legacy);
        }
    }

    private function normalizeAllLegacyProfileFolders(): void
    {
        $base = $this->basePath();
        if (! is_dir($base)) {
            return;
        }
        foreach (glob($base.'/profile_*') ?: [] as $legacy) {
            if (! is_dir($legacy)) {
                continue;
            }
            $id = (string) preg_replace('/^profile_/', '', basename($legacy));
            if ($id === '') {
                continue;
            }
            $this->normalizeLegacyEntityFolders('matrimony_profile', $id);
        }
    }

    public function countAllSnapshots(): int
    {
        $base = $this->basePath();
        if (! is_dir($base)) {
            return 0;
        }

        return count(File::allFiles($base));
    }

    /**
     * @return array{path: string, timestamp: string}|null
     */
    public function latestSnapshotMeta(): ?array
    {
        $base = $this->basePath();
        if (! is_dir($base)) {
            return null;
        }
        $files = File::allFiles($base);
        if ($files === []) {
            return null;
        }

        usort($files, fn (\SplFileInfo $a, \SplFileInfo $b): int => $b->getMTime() <=> $a->getMTime());
        $latest = $files[0];

        return [
            'path' => $latest->getPathname(),
            'timestamp' => date('c', $latest->getMTime()),
        ];
    }
}


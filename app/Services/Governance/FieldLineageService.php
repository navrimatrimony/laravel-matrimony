<?php

namespace App\Services\Governance;

/**
 * Phase-6I: field lineage derived from canonical governance registry (no static placeholders).
 */
class FieldLineageService
{
    private const REGISTRY_RELATIVE = 'python-data-engine/config/governance/canonical_field_registry.json';

    /**
     * @return array<string,mixed>
     */
    public function lineageFor(string $fieldPath): array
    {
        $entry = $this->registryEntryFor($fieldPath);
        if ($entry === null) {
            return [
                'field' => $fieldPath,
                'status' => 'unknown_field',
                'chain' => null,
                'source_paths' => null,
            ];
        }

        $paths = is_array($entry['source_paths'] ?? null) ? $entry['source_paths'] : [];

        return [
            'field' => (string) ($entry['field'] ?? $fieldPath),
            'category' => (string) ($entry['category'] ?? ''),
            'status' => 'active',
            'source_paths' => $paths,
            'chain' => $this->formatChain($paths),
            'governed' => (bool) ($entry['governed'] ?? false),
            'comparison_supported' => (bool) ($entry['comparison_supported'] ?? false),
            'repeater' => (bool) ($entry['repeater'] ?? false),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function lineageForFields(array $fieldNames): array
    {
        $out = [];
        foreach ($fieldNames as $f) {
            if (! is_string($f) || $f === '') {
                continue;
            }
            $out[] = $this->lineageFor($f);
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function registryEntryFor(string $field): ?array
    {
        $reg = $this->loadRegistry();
        foreach ($reg['entries'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['field'] ?? '') === $field) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadRegistry(): array
    {
        $path = base_path(self::REGISTRY_RELATIVE);
        if (! is_file($path)) {
            return ['entries' => []];
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? $raw : ['entries' => []];
    }

    /**
     * @param  array<string,mixed>  $paths
     */
    private function formatChain(array $paths): string
    {
        $w = (string) ($paths['wizard'] ?? '');
        $d = (string) ($paths['db'] ?? '');
        $a = (string) ($paths['api'] ?? '');
        $p = (string) ($paths['public_profile'] ?? '');
        $parts = array_filter([$w, $d, $a, $p], fn ($x) => $x !== '');

        return implode(' → ', $parts);
    }
}

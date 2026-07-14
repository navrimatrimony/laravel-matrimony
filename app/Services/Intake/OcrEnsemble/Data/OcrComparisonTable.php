<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

/**
 * Immutable OCR comparison table payload for operator review (Phase 5).
 *
 * @phpstan-type OcrComparisonTableArray array{
 *     columns: list<string>,
 *     rows: list<array<string, mixed>>,
 *     audit: array<string, mixed>
 * }
 */
final class OcrComparisonTable
{
    /**
     * @param  list<string>  $columns
     * @param  list<OcrComparisonFieldRow>  $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly OcrComparisonAuditMeta $audit,
    ) {}

    public static function empty(OcrComparisonAuditMeta $audit): self
    {
        return new self(
            columns: OcrEnsemblePhase5Constants::TABLE_COLUMNS,
            rows: [],
            audit: $audit,
        );
    }

    /**
     * @param  OcrComparisonTableArray  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = OcrComparisonFieldRow::fromArray($row);
        }

        return new self(
            columns: is_array($data['columns'] ?? null)
                ? array_values($data['columns'])
                : OcrEnsemblePhase5Constants::TABLE_COLUMNS,
            rows: $rows,
            audit: OcrComparisonAuditMeta::fromArray(
                is_array($data['audit'] ?? null) ? $data['audit'] : []
            ),
        );
    }

    /**
     * @return OcrComparisonTableArray
     */
    public function toArray(): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            $rows[] = $row->toArray();
        }

        return [
            'columns' => array_values($this->columns),
            'rows' => $rows,
            'audit' => $this->audit->toArray(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        $keys = [];
        foreach ($this->rows as $row) {
            $keys[] = $row->fieldKey;
        }

        return $keys;
    }
}

<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;

/**
 * Root JSON envelope persisted to biodata_intakes.field_resolution_json.
 *
 * @phpstan-type FieldResolutionEnvelopeArray array{
 *     _meta: array<string, mixed>,
 *     fields: array<string, array<string, mixed>>
 * }
 */
final class FieldResolutionEnvelope
{
    /**
     * @param  array<string, FieldResolutionFieldRecord>  $fields
     */
    public function __construct(
        public readonly FieldResolutionMeta $meta,
        public readonly array $fields,
    ) {}

    public static function skeleton(int $intakeId): self
    {
        $fields = [];
        foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
            $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton();
        }

        return new self(
            meta: FieldResolutionMeta::skeleton($intakeId),
            fields: $fields,
        );
    }

    /**
     * @param  FieldResolutionEnvelopeArray  $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = is_array($data['_meta'] ?? null) ? $data['_meta'] : [];
        $fieldsData = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        $fields = [];
        foreach ($fieldsData as $fieldKey => $fieldData) {
            if (! is_string($fieldKey) || ! is_array($fieldData)) {
                continue;
            }
            $fields[$fieldKey] = FieldResolutionFieldRecord::fromArray($fieldData);
        }

        return new self(
            meta: FieldResolutionMeta::fromArray($metaData),
            fields: $fields,
        );
    }

    /**
     * @return FieldResolutionEnvelopeArray
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $fieldKey => $record) {
            $fields[$fieldKey] = $record->toArray();
        }

        return [
            '_meta' => $this->meta->toArray(),
            'fields' => $fields,
        ];
    }
}

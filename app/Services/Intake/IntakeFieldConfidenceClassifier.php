<?php

namespace App\Services\Intake;

class IntakeFieldConfidenceClassifier
{
    private const CRITICAL_FIELDS = [
        'full_name',
        'date_of_birth',
        'primary_contact_number',
    ];

    private const IMPORTANT_FIELDS = [
        'caste',
        'religion',
        'education',
        'occupation',
        'height',
        'address',
    ];

    /**
     * @param  list<string>  $lowConfidenceFields
     * @return array{
     *     low_confidence_critical_fields: list<string>,
     *     low_confidence_important_fields: list<string>,
     *     low_confidence_optional_fields: list<string>,
     *     field_confidence_routing_severity: string,
     *     paid_vision_reasonable_for_field_confidence: bool
     * }
     */
    public function classify(array $lowConfidenceFields, bool $hasRawOcrText): array
    {
        $fields = $this->uniqueFields($lowConfidenceFields);
        $critical = [];
        $important = [];
        $optional = [];

        foreach ($fields as $field) {
            if (in_array($field, self::CRITICAL_FIELDS, true)) {
                $critical[] = $field;

                continue;
            }

            if (in_array($field, self::IMPORTANT_FIELDS, true)) {
                $important[] = $field;

                continue;
            }

            $optional[] = $field;
        }

        $severity = match (true) {
            $critical !== [] => 'critical',
            $important !== [] => 'important_only',
            $optional !== [] => 'optional_only',
            default => 'none',
        };

        return [
            'low_confidence_critical_fields' => $critical,
            'low_confidence_important_fields' => $important,
            'low_confidence_optional_fields' => $optional,
            'field_confidence_routing_severity' => $severity,
            'paid_vision_reasonable_for_field_confidence' => $severity === 'critical' && $hasRawOcrText,
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function uniqueFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field) {
            $field = trim($field);
            if ($field !== '') {
                $normalized[] = $field;
            }
        }

        return array_values(array_unique($normalized));
    }
}

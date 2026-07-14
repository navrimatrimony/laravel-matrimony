<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponseField;

/**
 * Parses Sarvam judge HTTP JSON into immutable response field DTOs.
 *
 * Supports:
 * - direct judge payload: { "fields": [ { "field_name", "value", ... } ] }
 * - map payload: { "judgements": { "date_of_birth": "..." } }
 * - chat completions wrapper: { "choices": [ { "message": { "content": "<json>" } } ] }
 */
final class OcrEnsembleSarvamJudgeResponseParser
{
    /**
     * @return array{
     *     ok: bool,
     *     outcome: string,
     *     error_code: string|null,
     *     error_message: string|null,
     *     fields: list<SarvamJudgeResponseField>
     * }
     */
    public function parse(string $rawBody): array
    {
        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return $this->fail(SarvamJudgeResponse::OUTCOME_EMPTY_RESPONSE, 'empty_body', 'Response body was empty');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->fail(SarvamJudgeResponse::OUTCOME_INVALID_JSON, 'malformed_json', $e->getMessage());
        }

        if (! is_array($decoded)) {
            return $this->fail(SarvamJudgeResponse::OUTCOME_INVALID_JSON, 'json_not_object', 'Decoded JSON was not an object/array');
        }

        if (isset($decoded['choices']) && is_array($decoded['choices'])) {
            $content = $decoded['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || trim($content) === '') {
                return $this->fail(SarvamJudgeResponse::OUTCOME_EMPTY_RESPONSE, 'empty_chat_content', 'Chat completion content was empty');
            }

            return $this->parse(trim($this->stripCodeFence($content)));
        }

        $fields = $this->extractFields($decoded);
        if ($fields === null) {
            return $this->fail(SarvamJudgeResponse::OUTCOME_INVALID_JSON, 'missing_fields', 'No fields/judgements found in response');
        }

        return [
            'ok' => true,
            'outcome' => SarvamJudgeResponse::OUTCOME_SUCCESS,
            'error_code' => null,
            'error_message' => null,
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<SarvamJudgeResponseField>|null
     */
    private function extractFields(array $decoded): ?array
    {
        if (isset($decoded['fields']) && is_array($decoded['fields'])) {
            $fields = [];
            $seen = [];
            foreach ($decoded['fields'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $field = SarvamJudgeResponseField::fromArray($row);
                if ($field->fieldName === '' || isset($seen[$field->fieldName])) {
                    continue;
                }
                if (! in_array($field->fieldName, OcrEnsemblePhase4Constants::TRIGGER_FIELDS, true)) {
                    continue;
                }
                $seen[$field->fieldName] = true;
                $fields[] = $field;
            }

            // Preserve frozen trigger order.
            usort($fields, static function (SarvamJudgeResponseField $a, SarvamJudgeResponseField $b): int {
                $order = array_flip(OcrEnsemblePhase4Constants::TRIGGER_FIELDS);

                return ($order[$a->fieldName] ?? 99) <=> ($order[$b->fieldName] ?? 99);
            });

            return $fields;
        }

        $map = $decoded['judgements'] ?? $decoded['values'] ?? null;
        if (! is_array($map)) {
            return null;
        }

        $fields = [];
        foreach (OcrEnsemblePhase4Constants::TRIGGER_FIELDS as $fieldKey) {
            if (! array_key_exists($fieldKey, $map)) {
                continue;
            }
            $fields[] = SarvamJudgeResponseField::fromArray([
                'field_name' => $fieldKey,
                'value' => is_string($map[$fieldKey]) || is_numeric($map[$fieldKey])
                    ? (string) $map[$fieldKey]
                    : null,
                'confidence' => null,
                'reason' => null,
            ]);
        }

        return $fields;
    }

    private function stripCodeFence(string $content): string
    {
        $trimmed = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $trimmed;
    }

    /**
     * @return array{
     *     ok: bool,
     *     outcome: string,
     *     error_code: string|null,
     *     error_message: string|null,
     *     fields: list<SarvamJudgeResponseField>
     * }
     */
    private function fail(string $outcome, string $errorCode, string $errorMessage): array
    {
        return [
            'ok' => false,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'fields' => [],
        ];
    }
}

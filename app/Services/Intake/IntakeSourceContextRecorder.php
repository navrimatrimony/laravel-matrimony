<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\IntakeSourceContext;

class IntakeSourceContextRecorder
{
    public function recordForIntake(BiodataIntake $intake, array $attributes): IntakeSourceContext
    {
        $attributes['biodata_intake_id'] = $intake->id;

        return $this->record($attributes);
    }

    public function record(array $attributes): IntakeSourceContext
    {
        $payload = $this->normalize($attributes);
        $idempotencyKey = $payload['idempotency_key'] ?? null;

        if ($idempotencyKey === null) {
            return IntakeSourceContext::create($payload);
        }

        $context = IntakeSourceContext::where('idempotency_key', $idempotencyKey)->first();
        if ($context === null) {
            return IntakeSourceContext::create($payload);
        }

        $nullableLinkFields = [
            'biodata_intake_id',
            'actor_user_id',
            'bulk_intake_batch_id',
            'bulk_intake_batch_item_id',
            'intake_whatsapp_session_id',
            'intake_whatsapp_message_id',
            'external_source_id',
        ];

        foreach ($nullableLinkFields as $field) {
            if ($context->{$field} === null && ($payload[$field] ?? null) !== null) {
                $context->{$field} = $payload[$field];
            }
        }

        if ($context->isDirty()) {
            $context->save();
        }

        return $context;
    }

    private function normalize(array $attributes): array
    {
        $sourceType = $this->stringOrNull($attributes['source_type'] ?? null);
        if (! in_array($sourceType, IntakeSourceContext::ALLOWED_SOURCE_TYPES, true)) {
            $sourceType = IntakeSourceContext::SOURCE_SYSTEM;
        }

        $actorType = $this->stringOrNull($attributes['actor_type'] ?? null);
        if (! in_array($actorType, IntakeSourceContext::ALLOWED_ACTOR_TYPES, true)) {
            $actorType = IntakeSourceContext::ACTOR_UNKNOWN;
        }

        $sourceSurface = $this->stringOrNull($attributes['source_surface'] ?? null);
        if (! in_array($sourceSurface, IntakeSourceContext::ALLOWED_SURFACES, true)) {
            $sourceSurface = null;
        }

        return [
            'biodata_intake_id' => $this->intOrNull($attributes['biodata_intake_id'] ?? null),
            'source_type' => $sourceType,
            'source_surface' => $sourceSurface,
            'actor_type' => $actorType,
            'actor_user_id' => $this->intOrNull($attributes['actor_user_id'] ?? null),
            'bulk_intake_batch_id' => $this->intOrNull($attributes['bulk_intake_batch_id'] ?? null),
            'bulk_intake_batch_item_id' => $this->intOrNull($attributes['bulk_intake_batch_item_id'] ?? null),
            'intake_whatsapp_session_id' => $this->intOrNull($attributes['intake_whatsapp_session_id'] ?? null),
            'intake_whatsapp_message_id' => $this->intOrNull($attributes['intake_whatsapp_message_id'] ?? null),
            'external_source_id' => $this->stringOrNull($attributes['external_source_id'] ?? null),
            'idempotency_key' => $this->stringOrNull($attributes['idempotency_key'] ?? null),
            'source_meta_json' => is_array($attributes['source_meta_json'] ?? null) ? $attributes['source_meta_json'] : null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}

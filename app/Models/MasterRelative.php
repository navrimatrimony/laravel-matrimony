<?php

namespace App\Models;

use App\Support\LocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MasterRelative extends Model
{
    protected $table = 'master_relatives';

    protected $fillable = [
        'key',
        'relation_group',
        'label',
        'label_mr',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function optionsForGroup(string $group): array
    {
        if (! Schema::hasTable('master_relatives')) {
            return self::fallbackOptionsForGroup($group);
        }

        $rows = self::query()
            ->where('relation_group', $group)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['key', 'label', 'label_mr']);

        if ($rows->isEmpty()) {
            return self::fallbackOptionsForGroup($group);
        }

        return $rows
            ->map(static function (self $relative): array {
                return [
                    'value' => (string) $relative->key,
                    'label' => LocalizedText::column($relative, 'label'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function fallbackOptionsForGroup(string $group): array
    {
        return match ($group) {
            'family_core' => [
                ['value' => 'father', 'label' => 'Father'],
                ['value' => 'mother', 'label' => 'Mother'],
            ],
            'sibling' => [
                ['value' => 'brother', 'label' => 'Brother'],
                ['value' => 'sister', 'label' => 'Sister'],
                ['value' => 'brother_wife', 'label' => 'Brother\'s wife'],
                ['value' => 'sister_husband', 'label' => 'Sister\'s husband'],
            ],
            'paternal' => [
                ['value' => 'paternal_grandfather', 'label' => 'Paternal Grandfather'],
                ['value' => 'paternal_grandmother', 'label' => 'Paternal Grandmother'],
                ['value' => 'paternal_uncle', 'label' => 'Paternal Uncle (chulte)'],
                ['value' => 'wife_paternal_uncle', 'label' => 'Wife of Paternal Uncle (chulti)'],
                ['value' => 'paternal_aunt', 'label' => 'Paternal Aunt (atya)'],
                ['value' => 'husband_paternal_aunt', 'label' => 'Husband of Paternal Aunt'],
                ['value' => 'Cousin', 'label' => 'Cousin'],
            ],
            'maternal' => [
                ['value' => 'maternal_address_ajol', 'label' => 'Maternal address (Ajol)'],
                ['value' => 'maternal_grandfather', 'label' => 'Maternal Grandfather'],
                ['value' => 'maternal_grandmother', 'label' => 'Maternal Grandmother'],
                ['value' => 'maternal_uncle', 'label' => 'Maternal Uncle (mama)'],
                ['value' => 'wife_maternal_uncle', 'label' => 'Wife of Maternal Uncle'],
                ['value' => 'maternal_aunt', 'label' => 'Maternal Aunt (mavshi)'],
                ['value' => 'husband_maternal_aunt', 'label' => 'Husband of Maternal Aunt'],
                ['value' => 'maternal_cousin', 'label' => 'Maternal Cousin'],
            ],
            default => [],
        };
    }
}

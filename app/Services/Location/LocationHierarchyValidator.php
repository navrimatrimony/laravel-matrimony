<?php

namespace App\Services\Location;

use App\Models\Location;
use Illuminate\Validation\ValidationException;

/**
 * Enforces SSOT rules for {@see Location} parent/type consistency and safe updates.
 */
class LocationHierarchyValidator
{
    public function __construct(
        private readonly LocationHierarchyIntegrityService $integrity,
    ) {}

    /**
     * @throws ValidationException
     */
    public function validate(Location $location): void
    {
        $type = (string) $location->type;
        $parentId = $location->parent_id !== null ? (int) $location->parent_id : null;

        if ($type === 'country') {
            if ($parentId !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A country location must not have a parent.'],
                ]);
            }

            return;
        }

        if ($type === 'state') {
            if ($parentId === null) {
                return;
            }
            $this->assertParentType($parentId, ['country'], $location);

            return;
        }

        if ($parentId === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent is required for this location type.'],
            ]);
        }

        $parent = Location::query()->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent location does not exist.'],
            ]);
        }

        $parentType = (string) $parent->type;

        match ($type) {
            'district' => $this->expectParentTypes($parentType, ['state'], 'district'),
            'taluka' => $this->expectParentTypes($parentType, ['district'], 'taluka'),
            'city' => $this->expectParentTypes($parentType, ['taluka', 'district'], 'city'),
            'suburb', 'village' => $this->expectParentTypes($parentType, ['city', 'taluka'], $type),
            default => null,
        };

        if ($location->exists) {
            if ($this->integrity->isInAncestorChain($parentId, (int) $location->id)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Parent assignment would create a circular hierarchy.'],
                ]);
            }

            if ($location->isDirty('type') && $location->children()->exists()) {
                throw ValidationException::withMessages([
                    'type' => ['Cannot change type while child locations exist.'],
                ]);
            }
        }
    }

    private function assertParentType(int $parentId, array $allowedTypes, Location $location): void
    {
        $parent = Location::query()->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent location does not exist.'],
            ]);
        }
        $this->expectParentTypes((string) $parent->type, $allowedTypes, (string) $location->type);
    }

    /**
     * @param  list<string>  $allowedParentTypes
     */
    private function expectParentTypes(string $parentType, array $allowedParentTypes, string $childTypeLabel): void
    {
        if (! in_array($parentType, $allowedParentTypes, true)) {
            throw ValidationException::withMessages([
                'parent_id' => [
                    'Invalid parent for '.ucfirst($childTypeLabel).': expected parent type '
                    .implode(' or ', $allowedParentTypes).', got '.ucfirst($parentType).'.',
                ],
            ]);
        }
    }
}

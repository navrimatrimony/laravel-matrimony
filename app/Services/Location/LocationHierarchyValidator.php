<?php

namespace App\Services\Location;

use App\Models\Location;
use Illuminate\Validation\ValidationException;

/**
 * Enforces SSOT rules for {@see Location} parent/hierarchy consistency and safe updates.
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
        $hierarchy = (string) $location->hierarchy;
        $parentId = $location->parent_id !== null ? (int) $location->parent_id : null;

        if ($hierarchy === 'country') {
            if ($parentId !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A country location must not have a parent.'],
                ]);
            }

            return;
        }

        if ($hierarchy === 'state') {
            if ($parentId === null) {
                return;
            }
            $this->assertParentType($parentId, ['country'], $location);

            return;
        }

        if ($parentId === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent is required for this location hierarchy.'],
            ]);
        }

        $parent = Location::query()->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent location does not exist.'],
            ]);
        }

        $parentHierarchy = (string) $parent->hierarchy;

        match ($hierarchy) {
            'district' => $this->expectParentTypes($parentHierarchy, ['state'], 'district'),
            'taluka' => $this->expectParentTypes($parentHierarchy, ['district'], 'taluka'),
            'village' => $this->expectParentTypes($parentHierarchy, ['taluka'], 'village'),
            default => throw ValidationException::withMessages([
                'hierarchy' => ['Invalid address hierarchy.'],
            ]),
        };

        if ($location->exists) {
            if ($this->integrity->isInAncestorChain($parentId, (int) $location->id)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Parent assignment would create a circular hierarchy.'],
                ]);
            }

            if ($location->isDirty('hierarchy') && $location->children()->exists()) {
                throw ValidationException::withMessages([
                    'hierarchy' => ['Cannot change hierarchy while child locations exist.'],
                ]);
            }
        }
    }

    private function assertParentType(int $parentId, array $allowedHierarchies, Location $location): void
    {
        $parent = Location::query()->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent location does not exist.'],
            ]);
        }
        $this->expectParentTypes((string) $parent->hierarchy, $allowedHierarchies, (string) $location->hierarchy);
    }

    /**
     * @param  list<string>  $allowedParentHierarchies
     */
    private function expectParentTypes(string $parentHierarchy, array $allowedParentHierarchies, string $childHierarchyLabel): void
    {
        if (! in_array($parentHierarchy, $allowedParentHierarchies, true)) {
            throw ValidationException::withMessages([
                'parent_id' => [
                    'Invalid parent for '.ucfirst($childHierarchyLabel).': expected parent hierarchy '
                    .implode(' or ', $allowedParentHierarchies).', got '.ucfirst($parentHierarchy).'.',
                ],
            ]);
        }
    }
}

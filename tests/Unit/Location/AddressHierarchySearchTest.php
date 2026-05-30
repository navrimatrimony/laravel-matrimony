<?php

namespace Tests\Unit\Location;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use App\Services\Location\AddressHierarchySearch;
use App\Services\LocationSearchService;
use Tests\TestCase;

class AddressHierarchySearchTest extends TestCase
{
    /** @var list<int> */
    private array $createdIds = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdIds) as $id) {
            Village::query()->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    public function test_hierarchy_search_finds_varkute_malavdi(): void
    {
        if (Country::query()->count() === 0) {
            $this->markTestSkipped('No location data in database');
        }

        $villageId = $this->seedVarkuteMalavdi();
        app()->setLocale('mr');

        $results = app(LocationSearchService::class)->search('वरकुटे-मलवडी, ता. माण, जि. सातारा', [], [], true);

        $this->assertNotEmpty($results['results'], 'Expected hierarchy search to return Varkute Malavadi');
        $ids = collect($results['results'])->pluck('city_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($villageId, $ids);
    }

    public function test_address_hierarchy_search_service_direct(): void
    {
        $components = [
            'village' => 'वरकुटे मलवडी',
            'taluka' => 'माण',
            'district' => 'सातारा',
        ];

        $cities = app(AddressHierarchySearch::class)->findCities($components, 5);

        if ($cities === []) {
            $this->markTestSkipped('Varkute Malavadi not present in addresses table');
        }

        $names = collect($cities)->map(fn ($c) => (string) ($c->name_mr ?? $c->name ?? ''))->implode(' ');
        $this->assertStringContainsString('वरकुटे', $names);
    }

    private function seedVarkuteMalavdi(): int
    {
        $satara = District::query()->where('name_mr', 'like', '%सातारा%')
            ->orWhere('name', 'like', '%Satara%')
            ->first();
        if ($satara === null) {
            $mh = State::query()->where('name', 'like', '%Maharashtra%')->first();
            if ($mh === null) {
                $this->markTestSkipped('Maharashtra state missing');
            }
            $satara = District::create(['name' => 'Satara Test', 'parent_id' => $mh->id, 'name_mr' => 'सातारा']);
            $this->createdIds[] = $satara->id;
        }

        $man = Taluka::query()
            ->where('parent_id', $satara->id)
            ->where(function ($q) {
                $q->where('name_mr', 'like', '%माण%')->orWhere('name', 'like', '%Man%');
            })
            ->first();
        if ($man === null) {
            $man = Taluka::create(['name' => 'Man Test', 'parent_id' => $satara->id, 'name_mr' => 'माण']);
            $this->createdIds[] = $man->id;
        }

        $existing = Village::query()
            ->where('parent_id', $man->id)
            ->where(function ($q) {
                $q->where('name_mr', 'like', '%वरकुटे%')->orWhere('name', 'like', '%Varkute%');
            })
            ->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $village = Village::create([
            'name' => 'Varkute Malavadi Test',
            'name_en' => 'Varkute Malavadi',
            'name_mr' => 'वरकुटे मलवडी',
            'parent_id' => $man->id,
        ]);
        $this->createdIds[] = $village->id;

        return (int) $village->id;
    }
}

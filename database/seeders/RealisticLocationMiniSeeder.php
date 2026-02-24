<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Removes synthetic stress-test location data and seeds realistic Maharashtra data.
 * Does not touch real cities (Pune, Pimpri-Chinchwad, Chakan) or Maharashtra structure from LocationSeeder.
 */
class RealisticLocationMiniSeeder extends Seeder
{
    private const VILLAGE_NAMES = [
        'Shivapur', 'Bhavaninagar', 'Khedgaon', 'Malkapur', 'Nandgaon',
        'Someshwarwadi', 'Kundal', 'Islampur', 'Tasgaon', 'Karadwadi',
        'Wadgaon', 'Shirgaon', 'Narayangaon', 'Rajgurunagar', 'Chakanwadi',
        'Manchar', 'Jejuri', 'Lonand', 'Koregaon', 'Phaltan',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $this->deleteSyntheticCities();
            $this->deleteSyntheticTalukas();
            $this->deleteSyntheticDistricts();
            $this->seedRealisticMaharashtra();
        });
    }

    private function deleteSyntheticCities(): void
    {
        City::where('name', 'like', 'Village-%')
            ->orWhereHas('taluka.district', function ($q) {
                $q->where('name', 'like', '%-%')
                    ->whereHas('state', fn ($q2) => $q2->whereIn('name', ['Gujarat', 'Karnataka', 'Madhya Pradesh']));
            })
            ->delete();
    }

    private function deleteSyntheticTalukas(): void
    {
        Taluka::where('name', 'like', 'Taluka-%')->delete();
    }

    private function deleteSyntheticDistricts(): void
    {
        District::where('name', 'like', '%-%')
            ->whereHas('state', fn ($q) => $q->whereIn('name', ['Gujarat', 'Karnataka', 'Madhya Pradesh']))
            ->delete();
    }

    private function seedRealisticMaharashtra(): void
    {
        $india = Country::firstOrCreate(['name' => 'India']);
        $maharashtra = State::firstOrCreate([
            'country_id' => $india->id,
            'name' => 'Maharashtra',
        ]);

        $districtNames = ['Pune', 'Satara', 'Sangli', 'Kolhapur', 'Nashik'];
        foreach ($districtNames as $dIndex => $districtName) {
            $district = District::firstOrCreate([
                'state_id' => $maharashtra->id,
                'name' => $districtName,
            ]);
            $districtNum = $dIndex + 1;

            $talukaNames = $this->talukaNamesForDistrict($districtName, $districtNum);
            foreach ($talukaNames as $tIndex => $talukaName) {
                $taluka = Taluka::firstOrCreate([
                    'district_id' => $district->id,
                    'name' => $talukaName,
                ]);
                $talukaNum = $tIndex + 1;

                $basePincode = 410000 + ($districtNum * 100) + (($talukaNum - 1) * 10);
                for ($v = 1; $v <= 10; $v++) {
                    $villageName = self::VILLAGE_NAMES[(($dIndex * 4 + $tIndex) * 10 + ($v - 1)) % count(self::VILLAGE_NAMES)];
                    $pincode = str_pad((string) ($basePincode + $v), 6, '0', STR_PAD_LEFT);
                    City::firstOrCreate(
                        [
                            'taluka_id' => $taluka->id,
                            'name' => $villageName,
                        ],
                        ['pincode' => $pincode]
                    );
                }
            }
        }
    }

    private function talukaNamesForDistrict(string $districtName, int $districtNum): array
    {
        $namesByDistrict = [
            'Pune' => ['Haveli', 'Mulshi', 'Shirur', 'Ambegaon'],
            'Satara' => ['Satara', 'Wai', 'Patan', 'Karad'],
            'Sangli' => ['Sangli', 'Miraj', 'Tasgaon', 'Kadegaon'],
            'Kolhapur' => ['Karveer', 'Shirol', 'Hatkanangale', 'Gadhinglaj'],
            'Nashik' => ['Nashik', 'Malegaon', 'Niphad', 'Yeola'],
        ];

        return $namesByDistrict[$districtName] ?? ['North', 'South', 'East', 'West'];
    }
}

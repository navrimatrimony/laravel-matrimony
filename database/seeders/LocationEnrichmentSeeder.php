<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;

class LocationEnrichmentSeeder extends Seeder
{
    public function run(): void
    {
        $mr = LocationMarathiLabels::englishToMarathi();
        Country::updateOrCreate(
            ['iso_alpha2' => 'IN'],
            [
                'name' => 'India',
                'name_mr' => $mr['India'] ?? 'भारत',
            ]
        );
        $india = Country::query()->where('iso_alpha2', 'IN')->first();
        $maharashtra = State::where('name', 'Maharashtra')->first();
        $gujarat = State::where('name', 'Gujarat')->first();
        if ($maharashtra) {
            LocationMarathiLabels::applyIfEmpty($maharashtra, $maharashtra->name);
        }
        if ($gujarat) {
            LocationMarathiLabels::applyIfEmpty($gujarat, $gujarat->name);
        }
        $pune = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Pune']);
        LocationMarathiLabels::applyIfEmpty($pune, $pune->name);
        $mumbaiSuburban = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Mumbai Suburban']);
        LocationMarathiLabels::applyIfEmpty($mumbaiSuburban, $mumbaiSuburban->name);
        $nashik = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Nashik']);
        LocationMarathiLabels::applyIfEmpty($nashik, $nashik->name);
        $kolhapur = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Kolhapur']);
        LocationMarathiLabels::applyIfEmpty($kolhapur, $kolhapur->name);
        $haveli = Taluka::firstOrCreate(['district_id' => $pune->id, 'name' => 'Haveli']);
        LocationMarathiLabels::applyIfEmpty($haveli, $haveli->name);
        $mulshi = Taluka::firstOrCreate(['district_id' => $pune->id, 'name' => 'Mulshi']);
        LocationMarathiLabels::applyIfEmpty($mulshi, $mulshi->name);
        $khed = Taluka::firstOrCreate(['district_id' => $pune->id, 'name' => 'Khed']);
        LocationMarathiLabels::applyIfEmpty($khed, $khed->name);
        $andheri = Taluka::firstOrCreate(['district_id' => $mumbaiSuburban->id, 'name' => 'Andheri']);
        LocationMarathiLabels::applyIfEmpty($andheri, $andheri->name);
        $borivali = Taluka::firstOrCreate(['district_id' => $mumbaiSuburban->id, 'name' => 'Borivali']);
        LocationMarathiLabels::applyIfEmpty($borivali, $borivali->name);
        $niphad = Taluka::firstOrCreate(['district_id' => $nashik->id, 'name' => 'Niphad']);
        LocationMarathiLabels::applyIfEmpty($niphad, $niphad->name);
        $sinnar = Taluka::firstOrCreate(['district_id' => $nashik->id, 'name' => 'Sinnar']);
        LocationMarathiLabels::applyIfEmpty($sinnar, $sinnar->name);
        $karveer = Taluka::firstOrCreate(['district_id' => $kolhapur->id, 'name' => 'Karveer']);
        LocationMarathiLabels::applyIfEmpty($karveer, $karveer->name);
        $hatkanangale = Taluka::firstOrCreate(['district_id' => $kolhapur->id, 'name' => 'Hatkanangale']);
        LocationMarathiLabels::applyIfEmpty($hatkanangale, $hatkanangale->name);
        City::firstOrCreate(['taluka_id' => $haveli->id, 'name' => 'Pune City']);
        City::firstOrCreate(['taluka_id' => $haveli->id, 'name' => 'Pimpri-Chinchwad']);
        City::firstOrCreate(['taluka_id' => $mulshi->id, 'name' => 'Paud']);
        City::firstOrCreate(['taluka_id' => $khed->id, 'name' => 'Rajgurunagar']);
        City::firstOrCreate(['taluka_id' => $andheri->id, 'name' => 'Andheri East']);
        City::firstOrCreate(['taluka_id' => $andheri->id, 'name' => 'Andheri West']);
        City::firstOrCreate(['taluka_id' => $borivali->id, 'name' => 'Borivali East']);
        City::firstOrCreate(['taluka_id' => $borivali->id, 'name' => 'Borivali West']);
        City::firstOrCreate(['taluka_id' => $niphad->id, 'name' => 'Niphad City']);
        City::firstOrCreate(['taluka_id' => $sinnar->id, 'name' => 'Sinnar City']);
        City::firstOrCreate(['taluka_id' => $karveer->id, 'name' => 'Kolhapur City']);
        City::firstOrCreate(['taluka_id' => $hatkanangale->id, 'name' => 'Ichalkaranji']);
        $ahmedabad = District::firstOrCreate(['state_id' => $gujarat->id, 'name' => 'Ahmedabad']);
        LocationMarathiLabels::applyIfEmpty($ahmedabad, $ahmedabad->name);
        $surat = District::firstOrCreate(['state_id' => $gujarat->id, 'name' => 'Surat']);
        LocationMarathiLabels::applyIfEmpty($surat, $surat->name);
        $vadodara = District::firstOrCreate(['state_id' => $gujarat->id, 'name' => 'Vadodara']);
        LocationMarathiLabels::applyIfEmpty($vadodara, $vadodara->name);
        $daskroi = Taluka::firstOrCreate(['district_id' => $ahmedabad->id, 'name' => 'Daskroi']);
        LocationMarathiLabels::applyIfEmpty($daskroi, $daskroi->name);
        $sanand = Taluka::firstOrCreate(['district_id' => $ahmedabad->id, 'name' => 'Sanand']);
        LocationMarathiLabels::applyIfEmpty($sanand, $sanand->name);
        $chorasi = Taluka::firstOrCreate(['district_id' => $surat->id, 'name' => 'Chorasi']);
        LocationMarathiLabels::applyIfEmpty($chorasi, $chorasi->name);
        $kamrej = Taluka::firstOrCreate(['district_id' => $surat->id, 'name' => 'Kamrej']);
        LocationMarathiLabels::applyIfEmpty($kamrej, $kamrej->name);
        $vadodaraTaluka = Taluka::firstOrCreate(['district_id' => $vadodara->id, 'name' => 'Vadodara Taluka']);
        LocationMarathiLabels::applyIfEmpty($vadodaraTaluka, $vadodaraTaluka->name);
        City::firstOrCreate(['taluka_id' => $daskroi->id, 'name' => 'Ahmedabad City']);
        City::firstOrCreate(['taluka_id' => $sanand->id, 'name' => 'Sanand City']);
        City::firstOrCreate(['taluka_id' => $chorasi->id, 'name' => 'Surat City']);
        City::firstOrCreate(['taluka_id' => $kamrej->id, 'name' => 'Kamrej City']);
        City::firstOrCreate(['taluka_id' => $vadodaraTaluka->id, 'name' => 'Vadodara City']);

        LocationMarathiLabels::syncIndianStateNameMr();
    }
}

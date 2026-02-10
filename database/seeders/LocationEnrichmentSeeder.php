<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\State;
use App\Models\District;
use App\Models\Taluka;
use App\Models\City;

class LocationEnrichmentSeeder extends Seeder
{
    public function run(): void
    {
        $india = Country::where('name', 'India')->first();
        $maharashtra = State::where('name', 'Maharashtra')->first();
        $gujarat = State::where('name', 'Gujarat')->first();
        $pune = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Pune']);
        $mumbaiSuburban = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Mumbai Suburban']);
        $nashik = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Nashik']);
        $kolhapur = District::firstOrCreate(['state_id' => $maharashtra->id, 'name' => 'Kolhapur']);
        $haveli = Taluka::firstOrCreate(['district_id' => $pune->id, 'name' => 'Haveli']);
        $mulshi = Taluka::firstOrCreate(['district_id' => $pune->id, 'name' => 'Mulshi']);
        $khed = Taluka::firstOrCreate(['district_id' => $pune->id, 'name' => 'Khed']);
        $andheri = Taluka::firstOrCreate(['district_id' => $mumbaiSuburban->id, 'name' => 'Andheri']);
        $borivali = Taluka::firstOrCreate(['district_id' => $mumbaiSuburban->id, 'name' => 'Borivali']);
        $niphad = Taluka::firstOrCreate(['district_id' => $nashik->id, 'name' => 'Niphad']);
        $sinnar = Taluka::firstOrCreate(['district_id' => $nashik->id, 'name' => 'Sinnar']);
        $karveer = Taluka::firstOrCreate(['district_id' => $kolhapur->id, 'name' => 'Karveer']);
        $hatkanangale = Taluka::firstOrCreate(['district_id' => $kolhapur->id, 'name' => 'Hatkanangale']);
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
        $surat = District::firstOrCreate(['state_id' => $gujarat->id, 'name' => 'Surat']);
        $vadodara = District::firstOrCreate(['state_id' => $gujarat->id, 'name' => 'Vadodara']);
        $daskroi = Taluka::firstOrCreate(['district_id' => $ahmedabad->id, 'name' => 'Daskroi']);
        $sanand = Taluka::firstOrCreate(['district_id' => $ahmedabad->id, 'name' => 'Sanand']);
        $chorasi = Taluka::firstOrCreate(['district_id' => $surat->id, 'name' => 'Chorasi']);
        $kamrej = Taluka::firstOrCreate(['district_id' => $surat->id, 'name' => 'Kamrej']);
        $vadodaraTaluka = Taluka::firstOrCreate(['district_id' => $vadodara->id, 'name' => 'Vadodara Taluka']);
        City::firstOrCreate(['taluka_id' => $daskroi->id, 'name' => 'Ahmedabad City']);
        City::firstOrCreate(['taluka_id' => $sanand->id, 'name' => 'Sanand City']);
        City::firstOrCreate(['taluka_id' => $chorasi->id, 'name' => 'Surat City']);
        City::firstOrCreate(['taluka_id' => $kamrej->id, 'name' => 'Kamrej City']);
        City::firstOrCreate(['taluka_id' => $vadodaraTaluka->id, 'name' => 'Vadodara City']);
    }
}
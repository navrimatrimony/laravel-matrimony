<?php

namespace Tests\Unit;

use App\Services\ManualSnapshotBuilderService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ManualSnapshotBuilderResidenceTest extends TestCase
{
    #[Test]
    public function raw_core_input_reads_snapshot_core_bracket_names(): void
    {
        $req = Request::create('/', 'POST', [
            'snapshot' => [
                'core' => [
                    'address_line' => 'सोसायटी, रस्ता',
                    'location_id' => '15',
                ],
            ],
        ]);

        $svc = app(ManualSnapshotBuilderService::class);
        $m = new ReflectionMethod(ManualSnapshotBuilderService::class, 'rawCoreInput');
        $m->setAccessible(true);

        $this->assertSame('सोसायटी, रस्ता', $m->invoke($svc, $req, 'address_line'));
        $this->assertSame('15', $m->invoke($svc, $req, 'location_id'));
    }

    #[Test]
    public function raw_core_input_prefers_snapshot_core_over_flat(): void
    {
        $req = Request::create('/', 'POST', [
            'address_line' => 'flat old',
            'snapshot' => [
                'core' => [
                    'address_line' => 'from snapshot',
                ],
            ],
        ]);

        $svc = app(ManualSnapshotBuilderService::class);
        $m = new ReflectionMethod(ManualSnapshotBuilderService::class, 'rawCoreInput');
        $m->setAccessible(true);

        $this->assertSame('from snapshot', $m->invoke($svc, $req, 'address_line'));
    }
}

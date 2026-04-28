<?php

namespace Tests\Feature\Location;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditDistrictDuplicatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_exits_zero_when_no_duplicates(): void
    {
        $this->artisan('location:audit-district-duplicates')
            ->assertExitCode(0);
    }
}

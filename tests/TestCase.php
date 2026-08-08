<?php

namespace Tests;

use App\Services\ProfileFieldConfigurationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Static memos do not roll back.
     *
     * RefreshDatabase resets the database between tests; it cannot reset a
     * `private static array` living in a service. ProfileFieldConfigurationService
     * memoises which fields are mandatory/enabled for the lifetime of the PHP
     * process, so the first test to ask a seeded database that question answers
     * it for every test after it — including ones running against an empty
     * table, which then behave as though configuration they never created is
     * still in force.
     *
     * The service already exposes the flush its own write events use; calling
     * it here makes each test start from the database it actually set up, and
     * removes a class of failures that only appear when a suite is run whole.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ProfileFieldConfigurationService::flushRuntimeCache();
    }
}

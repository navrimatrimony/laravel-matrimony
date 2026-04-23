<?php

namespace Tests\Unit;

use App\Models\MatrimonyProfile;
use App\Services\ContactAccessService;
use App\Services\ContactRevealPolicyService;
use App\Services\SubscriptionService;
use Tests\TestCase;

class ContactAccessVisibilityCaseTest extends TestCase
{
    private function revealPolicy(): ContactRevealPolicyService
    {
        return new ContactRevealPolicyService($this->createMock(SubscriptionService::class));
    }

    public function test_never_mode_is_no_one(): void
    {
        $svc = $this->revealPolicy();
        $p = new MatrimonyProfile(['contact_unlock_mode' => 'never']);

        $this->assertSame(
            ContactAccessService::CASE_NO_ONE,
            $svc->resolveVisibilityCase($p, (object) ['show_contact_to' => 'accepted_interest'])
        );
    }

    public function test_unlock_only_is_request_only(): void
    {
        $svc = $this->revealPolicy();
        $p = new MatrimonyProfile(['contact_unlock_mode' => 'after_interest_accepted']);

        $this->assertSame(
            ContactAccessService::CASE_REQUEST_ONLY,
            $svc->resolveVisibilityCase($p, (object) ['show_contact_to' => 'unlock_only'])
        );
    }

    public function test_accepted_interest_setting_is_paid_allowed(): void
    {
        $svc = $this->revealPolicy();
        $p = new MatrimonyProfile(['contact_unlock_mode' => 'after_interest_accepted']);

        $this->assertSame(
            ContactAccessService::CASE_PAID_ALLOWED,
            $svc->resolveVisibilityCase($p, (object) ['show_contact_to' => 'accepted_interest'])
        );
    }

    public function test_everyone_is_paid_allowed(): void
    {
        $svc = $this->revealPolicy();
        $p = new MatrimonyProfile(['contact_unlock_mode' => 'after_interest_accepted']);

        $this->assertSame(
            ContactAccessService::CASE_PAID_ALLOWED,
            $svc->resolveVisibilityCase($p, (object) ['show_contact_to' => 'everyone'])
        );
    }

    public function test_no_one_is_case_no_one(): void
    {
        $svc = $this->revealPolicy();
        $p = new MatrimonyProfile(['contact_unlock_mode' => 'after_interest_accepted']);

        $this->assertSame(
            ContactAccessService::CASE_NO_ONE,
            $svc->resolveVisibilityCase($p, (object) ['show_contact_to' => 'no_one'])
        );
    }
}

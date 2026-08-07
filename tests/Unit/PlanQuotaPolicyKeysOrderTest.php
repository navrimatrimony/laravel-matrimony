<?php

namespace Tests\Unit;

use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Tests\TestCase;

/**
 * Public/mobile plans comparison uses {@see PlanQuotaPolicyKeys::ordered()} as shared catalog order.
 */
class PlanQuotaPolicyKeysOrderTest extends TestCase
{
    public function test_contact_unlock_is_first_then_basic_then_silver_then_gold_ladder(): void
    {
        $keys = PlanQuotaPolicyKeys::ordered();

        $this->assertSame(PlanFeatureKeys::CONTACT_VIEW_LIMIT, $keys[0]);

        $index = static fn (string $key): int => array_search($key, $keys, true);

        // Basic-tier keys precede Silver-adds.
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::INTEREST_SEND_LIMIT));
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::CHAT_SEND_LIMIT));
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::PHOTO_FULL_ACCESS));
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::CHAT_CAN_READ));
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT));
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::INTEREST_VIEW_LIMIT));
        $this->assertLessThan($index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK), $index(PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH));
        $this->assertLessThan(
            $index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK),
            $index(SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT)
        );

        // Silver-adds in mock ladder order, then Gold-add slot last.
        $this->assertLessThan(
            $index(PlanFeatureKeys::PRIORITY_LISTING),
            $index(PlanFeatureKeys::PROFILE_BOOST_PER_WEEK),
        );
        $this->assertLessThan(
            $index(PlanFeatureKeys::ADVANCED_PROFILE_SEARCH),
            $index(PlanFeatureKeys::PRIORITY_LISTING),
        );
        $this->assertLessThan(
            $index(PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT),
            $index(PlanFeatureKeys::ADVANCED_PROFILE_SEARCH),
        );

        $this->assertSame(PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT, $keys[array_key_last($keys)]);
    }
}

<?php

namespace Tests\Unit\Payu;

use App\Services\Payu\MemberPayuActivationService;
use App\Services\Payu\PayuVerifyPaymentClient;
use App\Support\PayuHasher;
use Tests\TestCase;

class PayuNativeHardeningTest extends TestCase
{
    public function test_checkout_pro_dynamic_hash_v1_appends_salt(): void
    {
        $hash = PayuHasher::checkoutProDynamicHash('abc', 'salt');

        $this->assertSame(strtolower(hash('sha512', 'abcsalt')), $hash);
    }

    public function test_checkout_pro_dynamic_hash_v2_uses_hmac_sha256(): void
    {
        $hash = PayuHasher::checkoutProDynamicHash('abc', 'salt', 'V2');

        $this->assertSame(hash_hmac('sha256', 'abc', 'salt'), $hash);
    }

    public function test_verify_payment_client_is_not_configured_by_default(): void
    {
        config([
            'payu.verify_payment.enabled' => false,
            'payu.merchant_key' => 'key',
            'payu.merchant_salt' => 'salt',
            'payu.checkout_url' => 'https://test.payu.in/_payment',
        ]);

        $client = app(PayuVerifyPaymentClient::class);

        $this->assertFalse($client->isConfigured());
        $result = $client->verifyTransaction('TXN1');
        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['ok']);
    }

    public function test_pending_expired_uses_pending_expires_at(): void
    {
        $activation = app(MemberPayuActivationService::class);

        $this->assertFalse($activation->pendingExpired([]));
        $this->assertFalse($activation->pendingExpired([
            'pending_expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]));
        $this->assertTrue($activation->pendingExpired([
            'pending_expires_at' => now()->subMinute()->toIso8601String(),
        ]));
    }
}

<?php

namespace Tests\Feature\Http;

use App\Domain\Payment\Enums\FailureMode;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\Debug\FailureModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthorizesPayments;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class SandboxApiTest extends TestCase
{
    use AuthorizesPayments;
    use RefreshDatabase;
    use SeedsSandboxCards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
    }

    public function test_sandbox_cards_endpoint_lists_seeded_cards(): void
    {
        $response = $this->getJson('/sandbox/cards');

        $response->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonFragment(['token' => 'tok_approved_visa']);
    }

    public function test_sandbox_tokenization_maps_known_test_pan(): void
    {
        $response = $this->postJson('/sandbox/tokens', [
            'pan' => '4242 4242 4242 4242',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.token', 'tok_approved_visa')
            ->assertJsonPath('data.masked_pan', '************4242');
    }

    public function test_payments_and_attempts_endpoints_return_created_records(): void
    {
        $result = $this->authorizePayment(
            paymentId: 'pay_http_api_1',
            idempotencyKey: 'idem-http-auth-1',
        );

        $payments = $this->getJson('/payments');
        $payments->assertOk()
            ->assertJsonFragment(['payment_id' => 'pay_http_api_1']);

        $paymentDetail = $this->getJson('/payments/pay_http_api_1');
        $paymentDetail->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.authorization.status', 'approved');

        $attempts = $this->getJson('/payment-attempts?payment_id='.$result->payment->id);
        $attempts->assertOk()
            ->assertJsonFragment(['attempt_id' => $result->attempt->id]);

        $attemptDetail = $this->getJson('/payment-attempts/'.$result->attempt->id);
        $attemptDetail->assertOk()
            ->assertJsonPath('data.type', 'authorization');
    }

    public function test_debug_failure_mode_can_be_updated_and_read(): void
    {
        $this->postJson('/debug/failure-mode', ['mode' => 'always_decline'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'always_decline');

        $this->getJson('/debug/failure-mode')
            ->assertOk()
            ->assertJsonPath('data.mode', 'always_decline')
            ->assertJsonStructure(['data' => ['mode', 'available_modes']]);
    }

    public function test_debug_reset_clears_payments_and_restores_failure_mode(): void
    {
        $this->authorizePayment('pay_http_reset_1', idempotencyKey: 'idem-http-reset-auth-1');

        $this->app->make(FailureModeManager::class)->set(FailureMode::ProcessingDelay);

        $this->postJson('/debug/reset')
            ->assertOk()
            ->assertJsonPath('status', 'reset')
            ->assertJsonPath('failure_mode', 'normal');

        $this->assertSame(0, Payment::query()->count());
        $this->getJson('/debug/failure-mode')
            ->assertJsonPath('data.mode', 'normal');
    }

    public function test_debug_endpoints_are_disabled_when_config_is_off(): void
    {
        config(['payment_mock.debug.enabled' => false]);

        $this->postJson('/debug/reset')->assertForbidden();
    }
}

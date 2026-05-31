<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_index_endpoint_returns_service_metadata(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJson([
                'service' => 'stockflow-payment-mock',
                'status' => 'ok',
            ]);
    }

    public function test_ready_endpoint_returns_ready_when_database_is_available(): void
    {
        $this->get('/ready')
            ->assertOk()
            ->assertJson(['status' => 'ready']);
    }
}

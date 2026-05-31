<?php

namespace App\Http\Controllers;

use App\Infrastructure\Observability\PaymentMetricsRecorder;
use App\Infrastructure\Observability\Prometheus\PrometheusRegistry;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __construct(
        private readonly PrometheusRegistry $registry,
        private readonly PaymentMetricsRecorder $metricsRecorder,
    ) {}

    public function __invoke(): Response
    {
        if (! config('payment_mock.observability.metrics_enabled')) {
            abort(404);
        }

        $this->metricsRecorder->snapshotFailureMode();

        return response($this->registry->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}

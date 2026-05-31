<?php

namespace App\Http\Controllers;

use App\Domain\Payment\Enums\FailureMode;
use App\Domain\Payment\Services\Debug\DemoResetService;
use App\Domain\Payment\Services\Debug\FailureModeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebugController extends Controller
{
    public function __construct(
        private readonly FailureModeManager $failureModeManager,
        private readonly DemoResetService $demoResetService,
    ) {}

    public function showFailureMode(): JsonResponse
    {
        $this->ensureDebugEnabled();

        return response()->json([
            'data' => [
                'mode' => $this->failureModeManager->current()->value,
                'available_modes' => FailureMode::values(),
            ],
        ]);
    }

    public function setFailureMode(Request $request): JsonResponse
    {
        $this->ensureDebugEnabled();

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', FailureMode::values())],
        ]);

        $mode = FailureMode::from($validated['mode']);

        return response()->json([
            'data' => [
                'mode' => $this->failureModeManager->set($mode)->value,
            ],
        ]);
    }

    public function reset(): JsonResponse
    {
        $this->ensureDebugEnabled();

        $this->demoResetService->reset();

        return response()->json([
            'status' => 'reset',
            'failure_mode' => FailureMode::Normal->value,
        ]);
    }

    private function ensureDebugEnabled(): void
    {
        abort_unless(config('payment_mock.debug.enabled'), 403, 'Debug endpoints are disabled.');
    }
}

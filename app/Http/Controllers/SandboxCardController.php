<?php

namespace App\Http\Controllers;

use App\Application\Mappers\PaymentMapper;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use App\Domain\Payment\Services\Sandbox\SandboxTestCardTokenizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SandboxCardController extends Controller
{
    public function __construct(
        private readonly SandboxPaymentMethodResolver $resolver,
        private readonly SandboxTestCardTokenizer $tokenizer,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn ($card): array => PaymentMapper::sandboxCard($card),
                $this->resolver->all(),
            ),
        ]);
    }

    public function tokenize(Request $request): JsonResponse
    {
        if (! config('payment_mock.sandbox.allow_test_pan_tokenization')) {
            return response()->json([
                'message' => 'Test PAN tokenization is disabled. Use predefined sandbox tokens instead.',
            ], 403);
        }

        $validated = $request->validate([
            'pan' => ['required', 'string', 'min:12', 'max:24'],
        ]);

        $result = $this->tokenizer->tokenize($validated['pan']);

        if ($result === null) {
            return response()->json([
                'message' => 'Unknown test card number. Use predefined sandbox test cards only.',
            ], 422);
        }

        return response()->json([
            'data' => $result,
        ], 201);
    }
}

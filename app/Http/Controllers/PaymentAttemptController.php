<?php

namespace App\Http\Controllers;

use App\Application\Mappers\PaymentMapper;
use App\Domain\Payment\Models\PaymentAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PaymentAttempt::query()->orderByDesc('created_at');

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->string('payment_id')->toString());
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 100);

        $attempts = $query->limit($limit)->get();

        return response()->json([
            'data' => $attempts
                ->map(fn (PaymentAttempt $attempt): array => PaymentMapper::attempt($attempt))
                ->values()
                ->all(),
        ]);
    }

    public function show(string $attemptId): JsonResponse
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);

        return response()->json([
            'data' => PaymentMapper::attemptDetail($attempt),
        ]);
    }
}

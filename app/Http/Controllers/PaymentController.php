<?php

namespace App\Http\Controllers;

use App\Application\Mappers\PaymentMapper;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->string('order_id')->toString());
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->string('customer_id')->toString());
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 100);

        $payments = $query->limit($limit)->get();

        return response()->json([
            'data' => $payments
                ->map(fn (Payment $payment): array => PaymentMapper::payment($payment))
                ->values()
                ->all(),
        ]);
    }

    public function show(string $paymentId): JsonResponse
    {
        $payment = Payment::query()
            ->where('payment_id', $paymentId)
            ->firstOrFail();

        return response()->json([
            'data' => PaymentMapper::paymentDetail($payment),
        ]);
    }
}

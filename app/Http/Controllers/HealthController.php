<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'service' => config('payment_mock.service_name'),
            'status' => 'ok',
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return response()->json(['status' => 'ready']);
        } catch (\Throwable) {
            return response()->json(['status' => 'not_ready'], 503);
        }
    }
}

<?php

namespace App\Domain\Payment\Services\Debug;

use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\IdempotencyRecord;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Models\SandboxCard;
use Database\Seeders\SandboxCardSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class DemoResetService
{
    public function __construct(
        private readonly FailureModeManager $failureModeManager,
    ) {}

    public function reset(): void
    {
        DB::transaction(function (): void {
            Refund::query()->delete();
            Capture::query()->delete();
            Authorization::query()->delete();
            IdempotencyRecord::query()->delete();
            PaymentAttempt::query()->delete();
            Payment::query()->delete();
            SandboxCard::query()->delete();

            Artisan::call('db:seed', [
                '--class' => SandboxCardSeeder::class,
                '--force' => true,
            ]);
        });

        $this->failureModeManager->reset();
    }
}

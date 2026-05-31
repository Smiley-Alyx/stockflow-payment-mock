<?php

namespace Database\Seeders;

use App\Domain\Payment\Models\SandboxCard;
use App\Domain\Payment\Services\Sandbox\SandboxCardCatalog;
use Illuminate\Database\Seeder;

class SandboxCardSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = new SandboxCardCatalog;

        foreach ($catalog->definitions() as $definition) {
            SandboxCard::query()->updateOrCreate(
                ['token' => $definition['token']],
                [
                    'behavior' => $definition['behavior'],
                    'balance_value' => $definition['balance_value'],
                    'currency' => $definition['currency'],
                    'brand' => $definition['brand'],
                    'last_four' => $definition['last_four'],
                    'is_expired' => $definition['is_expired'],
                    'is_blocked' => $definition['is_blocked'],
                ],
            );
        }
    }
}

<?php

namespace Tests\Concerns;

use Database\Seeders\SandboxCardSeeder;

trait SeedsSandboxCards
{
    protected function seedSandboxCards(): void
    {
        $this->seed(SandboxCardSeeder::class);
    }
}

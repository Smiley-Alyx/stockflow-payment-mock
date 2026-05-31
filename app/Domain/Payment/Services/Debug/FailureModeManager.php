<?php

namespace App\Domain\Payment\Services\Debug;

use App\Domain\Payment\Enums\FailureMode;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class FailureModeManager
{
    private const CACHE_KEY = 'payment_mock:failure_mode';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function current(): FailureMode
    {
        $value = $this->cache->get(self::CACHE_KEY, FailureMode::Normal->value);

        return FailureMode::tryFrom((string) $value) ?? FailureMode::Normal;
    }

    public function set(FailureMode $mode): FailureMode
    {
        $this->cache->forever(self::CACHE_KEY, $mode->value);

        return $mode;
    }

    public function reset(): FailureMode
    {
        $this->cache->forget(self::CACHE_KEY);

        return FailureMode::Normal;
    }
}

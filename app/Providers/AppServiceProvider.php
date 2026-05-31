<?php

namespace App\Providers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Domain\Payment\Services\Debug\DemoResetService;
use App\Domain\Payment\Services\Debug\FailureModeManager;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Domain\Payment\Services\PaymentLedger;
use App\Domain\Payment\Services\Refund\PaymentRefundService;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use App\Domain\Payment\Services\Sandbox\SandboxCardBehaviorEvaluator;
use App\Domain\Payment\Services\Sandbox\SandboxCardCatalog;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use App\Domain\Payment\Services\Sandbox\SandboxTestCardTokenizer;
use App\Domain\Payment\Services\Sandbox\SensitiveDataMasker;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaderValidator;
use App\Infrastructure\Messaging\RabbitMq\IdempotentPaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\NullPaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use App\Infrastructure\Messaging\RabbitMq\PublishedEventStore;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqMessagePublisher;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqTopologyManager;
use App\Application\Mappers\PaymentEventPayloadMapper;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SandboxCardCatalog::class);
        $this->app->singleton(SensitiveDataMasker::class);
        $this->app->singleton(SandboxPaymentMethodResolver::class);
        $this->app->singleton(SandboxCardBehaviorEvaluator::class);
        $this->app->singleton(SandboxTestCardTokenizer::class);
        $this->app->singleton(SandboxBalanceService::class);
        $this->app->singleton(PaymentIdempotencyService::class);
        $this->app->singleton(PaymentAuthorizationService::class);
        $this->app->singleton(PaymentLedger::class);
        $this->app->singleton(PaymentCaptureService::class);
        $this->app->singleton(PaymentRefundService::class);
        $this->app->singleton(FailureModeManager::class);
        $this->app->singleton(DemoResetService::class);

        $this->app->singleton(RabbitMqConfig::class, fn (): RabbitMqConfig => RabbitMqConfig::fromConfig());
        $this->app->singleton(RabbitMqConnectionFactory::class);
        $this->app->singleton(RabbitMqTopologyManager::class);
        $this->app->singleton(MessageHeaderValidator::class);
        $this->app->singleton(PaymentMessageMapper::class);
        $this->app->singleton(OutgoingMessageHeadersFactory::class);
        $this->app->singleton(PaymentEventPayloadMapper::class);
        $this->app->singleton(PublishedEventStore::class);
        $this->app->singleton(RabbitMqMessagePublisher::class);
        $this->app->singleton(PaymentEventPublisher::class, function ($app): PaymentEventPublisher {
            if (! config('payment_mock.rabbitmq.publish_events')) {
                return $app->make(NullPaymentEventPublisher::class);
            }

            return $app->make(IdempotentPaymentEventPublisher::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

# Architecture

`stockflow-payment-mock` is a sandbox payment provider for the Stockflow marketplace
case study. It simulates an external PSP (payment service provider) that the marketplace
integrates with over RabbitMQ, while exposing a small HTTP API for local inspection
and demo tooling.

This is **not** a production payment system. No real card data is stored or processed.

## StockFlow ecosystem

Part of the StockFlow ecosystem:

- [stockflow-market](https://github.com/Smiley-Alyx/stockflow-market) — marketplace backend case study
- [stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock) — external ERP / inventory integration mock
- [stockflow-payment-mock](https://github.com/Smiley-Alyx/stockflow-payment-mock) — external payment provider mock (this repository)
- [stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock) — external delivery provider mock

The marketplace is the orchestrator. External mocks are independent services with
their own HTTP APIs, persistence, and RabbitMQ topology. Integration is
contract-first: each boundary publishes AsyncAPI specs and JSON Schemas under
`contracts/`.

| Repository | RabbitMQ exchange | Integration role |
| --- | --- | --- |
| [stockflow-market](https://github.com/Smiley-Alyx/stockflow-market) | — (orchestrator) | Checkout, orders, publishes provider requests, consumes outcomes |
| [stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock) | `stockflow.inventory` | Inventory reservation and release |
| [stockflow-payment-mock](https://github.com/Smiley-Alyx/stockflow-payment-mock) | `stockflow.payment` | Payment authorization, capture, refund |
| [stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock) | `stockflow.delivery` | Shipment creation, cancellation, status events |

Cross-service correlation uses the same `correlation_id` on every message in a
checkout flow. Each outbound event sets `causation_id` to the incoming request
`message_id`, so the market can trace causality across ERP, payment, and delivery
boundaries.

## System context

```text
                         ┌─────────────────────────────────────────┐
                         │           stockflow-market              │
                         │  checkout · orders · fulfillment        │
                         └───────┬─────────────┬─────────────┬───────┘
                                 │             │             │
                    inventory    │   payment   │   delivery  │
                    reservation  │   auth/cap  │   shipment  │
                                 │             │             │
         ┌───────────────────────▼──┐  ┌───────▼────────┐  ┌──▼────────────────────┐
         │   stockflow-erp-mock     │  │ stockflow-     │  │ stockflow-delivery-   │
         │   stockflow.inventory    │  │ payment-mock   │  │ mock                  │
         │                          │  │ stockflow.     │  │ stockflow.delivery    │
         │                          │  │ payment        │  │                       │
         └──────────────────────────┘  │ (this service) │  └───────────────────────┘
                                       └────────────────┘
                                 RabbitMQ · AsyncAPI v1 · shared headers
```

| Integration | Role |
| --- | --- |
| RabbitMQ | Primary integration path for authorization, capture, and refund |
| HTTP API | Sandbox card catalog, payment inspection, debug/demo controls |
| SQLite / PostgreSQL-ready | Persistent payment state, idempotency, sandbox balances |

Contracts live in [`contracts/`](../contracts/). Runtime topology is declared by
`RabbitMqTopologyManager` on consumer startup.

## Layered design

```text
app/
  Application/          Handlers, mappers (AMQP ↔ domain DTOs)
  Domain/Payment/       Business rules, models, enums, domain services
  Infrastructure/       RabbitMQ, observability, external I/O adapters
  Http/                 Sandbox and debug REST controllers
  Support/              Structured logging helpers
```

| Layer | Responsibility |
| --- | --- |
| **Application** | Route incoming messages to domain services; map domain results to outbound events |
| **Domain** | Payment lifecycle, sandbox card behavior, idempotency, ledger |
| **Infrastructure** | AMQP consume/publish, retry/DLQ, Prometheus registry, event store |
| **HTTP** | Read-only views and debug controls gated by config |

Dependency direction: Application → Domain ← Infrastructure adapters.

## Core domain components

| Component | Purpose |
| --- | --- |
| `PaymentAuthorizationService` | Create payment, reserve sandbox balance, approve/decline |
| `PaymentCaptureService` | Manual capture against an approved authorization |
| `PaymentRefundService` | Full or partial refund against captured funds |
| `PaymentIdempotencyService` | `(payment_id, operation, idempotency_key)` replay |
| `PaymentLedger` | Captured / refunded / refundable amounts |
| `SandboxBalanceService` | Atomic balance reserve/release with row locks |
| `SandboxCardBehaviorEvaluator` | Token-driven decline rules |

## Messaging components

| Component | Purpose |
| --- | --- |
| `PaymentRequestConsumer` | Long-running worker on requests + retry queues |
| `PaymentRequestProcessor` | Process one request message (ack/retry/DLQ) |
| `PaymentMessageDispatcher` | Route by routing key to auth/capture/refund handlers |
| `IdempotentPaymentEventPublisher` | Publish result events; deduplicate outbound on replay |
| `PublishedEventStore` | Persist first outbound event per idempotency scope |
| `PaymentRequestFailureHandler` | Retry policy, DLQ routing, invalid message reject |
| `PaymentRetryRequeueHandler` | Move delayed retry messages back to main exchange |

## RabbitMQ topology

| Resource | Name |
| --- | --- |
| Exchange | `stockflow.payment` |
| Dead-letter exchange | `stockflow.payment.dlx` |
| Request queue | `stockflow.payment.requests` |
| Retry queue | `stockflow.payment.requests.retry` |
| DLQ | `stockflow.payment.requests.dlq` |

Incoming routing keys:

- `payment.authorization.requested.v1`
- `payment.capture.requested.v1`
- `payment.refund.requested.v1`

Outgoing routing keys follow the same naming pattern with outcome suffixes
(`approved`, `declined`, `completed`, `failed`).

## Idempotency model

Two layers protect against duplicate side effects:

1. **Domain idempotency** — `idempotency_records` keyed by
   `(operation, payment_id, idempotency_key)`. Replays return the same attempt and
   do not debit sandbox balance twice.

2. **Outbound idempotency** — `published_event_records` stores the first result
   event. Retries republish the **same** `message_id` and payload so the marketplace
   can reconcile duplicate deliveries.

## Observability

| Surface | Details |
| --- | --- |
| `GET /metrics` | Prometheus text format (counters, histograms, failure-mode gauge) |
| Structured logs | `service` + `event` fields on lifecycle log lines |
| Health | `/health`, `/ready`, `/up` |

Key metrics: `payment_requests_total`, `payment_processing_duration_seconds`,
`payment_events_published_total`, `payment_request_retries_total`,
`payment_request_dlq_total`.

## Runtime processes

| Process | Command |
| --- | --- |
| HTTP API | `make serve` / Docker `payment-mock` |
| Request consumer | `make consume` / Docker `payment-mock-worker` |
| DLQ requeue | `php artisan payment-mock:requeue-dlq` |

## Testing strategy

| Suite | Location | Broker required |
| --- | --- | --- |
| Unit + feature | `tests/Unit`, `tests/Feature` | No |
| RabbitMQ integration | `tests/Integration` | Yes |
| Contract examples | `tests/Unit/Contracts` | No |

CI runs both suites in [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

## Security and sandbox constraints

- Only predefined **card tokens** (`tok_*`) are accepted in message payloads.
- Optional PAN tokenization masks input and never stores CVV.
- Debug endpoints (`/debug/*`) are disabled unless `PAYMENT_MOCK_DEBUG_ENABLED=true`.

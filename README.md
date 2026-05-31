# Stockflow Payment Mock

`stockflow-payment-mock` is an external sandbox service that emulates a payment
provider integration for the `stockflow-market` case study. It is **not** a real
payment gateway: all card data is test-only and tokenized.

The service is built with PHP 8.3 and Laravel. It communicates with the
marketplace through RabbitMQ and AsyncAPI contracts, and exposes an HTTP API for
sandbox card simulation, health checks, and local demo tooling.

## StockFlow ecosystem

Part of the StockFlow ecosystem:

- [stockflow-market](https://github.com/Smiley-Alyx/stockflow-market) — marketplace backend case study
- [stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock) — external ERP / inventory integration mock
- [stockflow-payment-mock](https://github.com/Smiley-Alyx/stockflow-payment-mock) — external payment provider mock (this repository)
- [stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock) — external delivery provider mock

`stockflow-market` orchestrates checkout and order fulfillment. Each external mock
implements one provider boundary over RabbitMQ with AsyncAPI contracts, shared
header conventions (`correlation_id`, `idempotency_key`, `causation_id`), and
retry/DLQ handling:

| Service | Exchange | Responsibility |
| --- | --- | --- |
| [stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock) | `stockflow.inventory` | Reserve and release stock in the external ERP sandbox |
| **stockflow-payment-mock** (this repo) | `stockflow.payment` | Authorize, capture, and refund card payments |
| [stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock) | `stockflow.delivery` | Create shipments and publish tracking status events |

A typical checkout in the case study chains these boundaries: the marketplace
reserves inventory, requests payment authorization (and later capture), then
requests shipment creation once the order is paid. The same `correlation_id`
ties messages across all three integrations so the market can reconstruct the
full order timeline.

See [`docs/architecture.md`](docs/architecture.md#stockflow-ecosystem) for the
end-to-end diagram and links to sibling repositories.

## Local run

```bash
make install
make migrate
make serve
```

The HTTP server listens on `http://localhost:8081`.

## Git hooks

Install project hooks and the commit message template:

```bash
composer install-git-hooks
```

Commit messages must follow conventional commits as `type(scope): subject`, where
`scope` is required and written in kebab-case.

Examples:

```text
feat(authorization): add sandbox card authorization service
refactor(idempotency): extract duplicate request handling
test(rabbitmq): cover authorization happy path over amqp
docs(payment-flow): describe capture and refund sequence
infra(observability): add prometheus metrics endpoint
chore(bootstrap): initialize laravel payment mock service
```

## Docker Compose

Start the service with a local RabbitMQ instance:

```bash
make docker-up
```

| Service | URL |
| --- | --- |
| Payment mock HTTP | `http://localhost:8081` |
| RabbitMQ AMQP | `localhost:5673` |
| RabbitMQ management UI | `http://localhost:15673` |

Use `stockflow` as both username and password for the local RabbitMQ environment.

Stop the containers:

```bash
make docker-down
```

## HTTP endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/` | Service metadata |
| `GET` | `/health` | Liveness probe |
| `GET` | `/ready` | Readiness probe (database connectivity) |
| `GET` | `/up` | Laravel built-in health check |
| `GET` | `/sandbox/cards` | List sandbox card tokens and balances |
| `POST` | `/sandbox/tokens` | Map known test PAN to sandbox token (optional) |
| `GET` | `/payments` | List payments |
| `GET` | `/payments/{payment_id}` | Payment details |
| `GET` | `/payment-attempts` | List payment attempts |
| `GET` | `/payment-attempts/{attempt_id}` | Attempt details |
| `GET` | `/debug/failure-mode` | Current provider failure simulation mode |
| `POST` | `/debug/failure-mode` | Set provider failure simulation mode |
| `POST` | `/debug/reset` | Reset demo data and failure mode |

## Messaging contract

AsyncAPI and JSON Schemas live in [`contracts/`](contracts/):

- [`contracts/asyncapi.yaml`](contracts/asyncapi.yaml)
- [`contracts/messages/`](contracts/messages/)
- [`contracts/examples/`](contracts/examples/)
- [`contracts/README.md`](contracts/README.md) — headers, correlation, idempotency

## RabbitMQ consumer

Run the request consumer locally:

```bash
make consume
```

Docker Compose starts a worker service automatically:

```bash
make docker-up
```

Consumer command:

```bash
php artisan payment-mock:consume-requests
```

The worker consumes:

- `payment.authorization.requested.v1`
- `payment.capture.requested.v1`
- `payment.refund.requested.v1`

After processing, it publishes result events to the same exchange:

- `payment.authorization.approved.v1` / `payment.authorization.declined.v1`
- `payment.capture.completed.v1` / `payment.capture.failed.v1`
- `payment.refund.completed.v1` / `payment.refund.failed.v1`

Outgoing headers preserve `correlation_id` and `idempotency_key` from the request,
set `causation_id` to the request `message_id`, and assign a new `message_id` per event.

Set `PAYMENT_MOCK_PUBLISH_EVENTS=false` to disable AMQP publishing (tests use the null publisher).

Duplicate RabbitMQ requests with the same `idempotency_key` replay the stored domain
result and republish the original outbound event (`message_id` and payload unchanged).

### Retry and DLQ

Transient processing failures are retried through `stockflow.payment.requests.retry`:

1. Consumer acks the failed request and publishes it to the retry queue with
   `x-retry-count` and `x-original-routing-key`.
2. The retry consumer republishes the message to the main exchange when the optional
   `x-retry-after` delay has elapsed.
3. After `RABBITMQ_MAX_RETRY_ATTEMPTS`, the message is moved to
   `stockflow.payment.requests.dlq` with failure metadata.

Invalid payloads are rejected immediately to the DLQ (no retry).

Manual requeue:

```bash
php artisan payment-mock:requeue-dlq --limit=10
```

Debug API (when `PAYMENT_MOCK_DEBUG_ENABLED=true`):

```text
GET  /debug/dlq
POST /debug/dlq/requeue
GET  /debug/failure-mode
POST /debug/failure-mode
POST /debug/reset
```

### Failure modes

Set via `POST /debug/failure-mode` (`{"mode":"always_decline"}`):

| Mode | Effect |
| --- | --- |
| `normal` | Sandbox card tokens drive behavior |
| `always_decline` | Decline auth/capture/refund regardless of card |
| `random_decline` | 50% decline per operation |
| `processing_delay` | Sleep `PAYMENT_MOCK_PROCESSING_DELAY_MS` before processing |
| `provider_unavailable` | Throw retryable error (RabbitMQ retry/DLQ path) |
| `timeout` | Throw retryable timeout error |
| `capture_failure` | Force capture failure |
| `refund_failure` | Force refund failure |
| `duplicate_response` | Publish the same outbound event twice |
| `publish_failure` | Throw retryable error while publishing result events |

## Observability

Prometheus metrics are exposed at `GET /metrics` when `PAYMENT_MOCK_METRICS_ENABLED=true`.

| Metric | Description |
| --- | --- |
| `payment_requests_total` | Processed RabbitMQ requests by operation/outcome |
| `payment_processing_duration_seconds` | Request processing latency histogram |
| `payment_events_published_total` | Outbound payment events |
| `payment_request_retries_total` | Messages scheduled for retry |
| `payment_request_dlq_total` | Messages moved to DLQ |
| `payment_failure_mode_active` | Current debug failure mode gauge |

Structured logs include `service` and `event` fields on payment lifecycle log lines.

## Integration tests

RabbitMQ integration tests live in `tests/Integration` and require a running broker.

```bash
make docker-up
make test-integration
```

They connect to `127.0.0.1:5673` when run locally via `make test-integration` (docker-compose maps RabbitMQ to host port 5673). The job fails fast if the broker is not running.

## CI

GitHub Actions runs on every push and pull request:

| Job | Command | Notes |
| --- | --- | --- |
| `PHPUnit` | `composer test` | Unit and feature tests (131+) |
| `RabbitMQ integration` | `composer test:integration` | End-to-end AMQP flow with a RabbitMQ service container |

Local equivalents:

```bash
make test
make docker-up
make test-integration
```

Workflow file: [`.github/workflows/ci.yml`](.github/workflows/ci.yml)

## Configuration

| Environment variable | Default | Description |
| --- | --- | --- |
| `PAYMENT_MOCK_SERVICE_NAME` | `stockflow-payment-mock` | Service identifier in logs and events |
| `PAYMENT_MOCK_HTTP_PORT` | `8080` | HTTP port inside the container |
| `RABBITMQ_HOST` | `127.0.0.1` | RabbitMQ host |
| `RABBITMQ_PORT` | `5672` | RabbitMQ port |
| `RABBITMQ_USER` | `stockflow` | RabbitMQ username |
| `RABBITMQ_PASSWORD` | `stockflow` | RabbitMQ password |
| `PAYMENT_MOCK_DEBUG_ENABLED` | `false` | Enable `/debug/*` endpoints |
| `PAYMENT_MOCK_ALLOW_TEST_PAN_TOKENIZATION` | `false` | Enable `POST /sandbox/tokens` |
| `PAYMENT_MOCK_PUBLISH_EVENTS` | `true` | Publish payment result events to RabbitMQ |
| `RABBITMQ_MAX_RETRY_ATTEMPTS` | `3` | Retry count before a request is moved to DLQ |
| `RABBITMQ_RETRY_DELAY_MS` | `5000` | Delay before a retried request is requeued |
| `PAYMENT_MOCK_PROCESSING_DELAY_MS` | `2000` | Artificial latency for `processing_delay` mode |
| `PAYMENT_MOCK_METRICS_ENABLED` | `true` | Expose Prometheus metrics at `GET /metrics` |

## Portfolio scope

This repository is part of the [StockFlow ecosystem](#stockflow-ecosystem): a
highload-oriented marketplace backend case study with external service mocks.
It demonstrates:

- authorization / capture / refund lifecycle over RabbitMQ
- sandbox card token behavior and failure simulation
- idempotent message handling with retry and DLQ
- Prometheus metrics and structured observability
- AsyncAPI contracts and integration tests

See [`docs/`](docs/) for architecture notes and demo scenarios:

- [`docs/architecture.md`](docs/architecture.md)
- [`docs/payment-flow.md`](docs/payment-flow.md)
- [`docs/failure-modes.md`](docs/failure-modes.md)
- [`docs/demo.md`](docs/demo.md)

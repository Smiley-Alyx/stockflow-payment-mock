# Stockflow Payment Mock

`stockflow-payment-mock` is an external sandbox service that emulates a payment
provider integration for the `stockflow-market` case study. It is **not** a real
payment gateway: all card data is test-only and tokenized.

The service is built with PHP 8.3 and Laravel. It will communicate with the
marketplace through RabbitMQ and AsyncAPI contracts, and expose an HTTP API for
sandbox card simulation, health checks, and local demo tooling.

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

## Portfolio scope

This repository demonstrates:

- authorization / capture / refund lifecycle over RabbitMQ
- sandbox card token behavior and failure simulation
- idempotent message handling with retry and DLQ
- Prometheus metrics and structured observability
- AsyncAPI contracts and integration tests

See `docs/` (upcoming steps) for architecture notes and demo scenarios.

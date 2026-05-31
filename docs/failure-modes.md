# Failure modes

The payment mock supports two complementary ways to simulate failures:

1. **Sandbox card tokens** — per-card behavior baked into the catalog.
2. **Debug failure modes** — global provider degradation toggled at runtime.

Both are safe for demos: no real payments, no PAN/CVV storage.

Part of the StockFlow ecosystem:
[stockflow-market](https://github.com/Smiley-Alyx/stockflow-market),
[stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock),
[stockflow-payment-mock](https://github.com/Smiley-Alyx/stockflow-payment-mock),
[stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock).

Sibling mocks use the same patterns — sandbox tokens or flags, retry queues, DLQ,
and Prometheus metrics — so the market can exercise end-to-end degradation across
inventory, payment, and delivery boundaries. See
[`architecture.md`](architecture.md#stockflow-ecosystem) for how the services
interact.

## Sandbox card tokens

Listed via `GET /sandbox/cards`. Seeded on `make migrate`.

| Token | Behavior | Typical decline / effect |
| --- | --- | --- |
| `tok_approved_visa` | Happy path | Authorization approved |
| `tok_insufficient_funds` | Low balance | `INSUFFICIENT_FUNDS` on auth |
| `tok_expired_card` | Expired flag | `CARD_EXPIRED` on auth |
| `tok_blocked_card` | Blocked flag | `CARD_BLOCKED` on auth |
| `tok_random_decline` | 50% random | `DECLINED` on auth |
| `tok_processing_delay` | Card-level delay behavior | Evaluated by card rules |
| `tok_provider_unavailable` | Provider down | `PROVIDER_UNAVAILABLE` on auth |
| `tok_capture_failure` | Auth OK, capture fails | `CAPTURE_FAILED` on capture |
| `tok_refund_failure` | Capture OK, refund fails | `REFUND_FAILED` on refund |

Decline reason codes are defined in `DeclineReasonCode` and appear in outbound
AMQP payloads and HTTP attempt records.

### Balance model

- **Authorization approved** → balance reserved (debited).
- **Capture failed** → authorization amount released.
- **Refund completed** → refunded amount released back to card balance.
- **Idempotent replay** → no second debit/credit.

## Debug failure modes

Enabled when `PAYMENT_MOCK_DEBUG_ENABLED=true`.

```bash
# Read current mode
curl http://localhost:8081/debug/failure-mode

# Set mode
curl -X POST http://localhost:8081/debug/reset
curl -X POST http://localhost:8081/debug/failure-mode \
  -H 'Content-Type: application/json' \
  -d '{"mode":"always_decline"}'
```

| Mode | Effect |
| --- | --- |
| `normal` | Only sandbox card tokens determine outcome |
| `always_decline` | Force decline on auth, capture, and refund |
| `random_decline` | 50% decline per operation |
| `processing_delay` | Sleep `PAYMENT_MOCK_PROCESSING_DELAY_MS` before processing |
| `provider_unavailable` | Retryable error → RabbitMQ retry/DLQ path |
| `timeout` | Retryable timeout error |
| `capture_failure` | Force capture failure (auth still succeeds) |
| `refund_failure` | Force refund failure |
| `duplicate_response` | Publish the same outbound event twice |
| `publish_failure` | Retryable error during event publish |

Debug modes are applied by `ProviderDegradationSimulator` before domain logic
runs (except publish-only modes which affect the event publisher).

Reset everything (payments, failure mode, sandbox balances):

```bash
curl -X POST http://localhost:8081/debug/reset
```

## Messaging failure handling

```text
Request received
       │
       ▼
  Valid headers/payload? ──no──► reject → DLQ
       │
      yes
       │
       ▼
  Process domain logic
       │
       ├── success ──► publish result event ──► ack
       │
       └── retryable error ──► retry queue ──► requeue ──► retry
                    │
                    └── max retries ──► DLQ (+ failure headers)
```

### DLQ metadata

Messages in `stockflow.payment.requests.dlq` include:

| Header | Description |
| --- | --- |
| `x-original-routing-key` | Request routing key for requeue |
| `x-retry-count` | Attempts before DLQ |
| `x-failure-reason` | Exception class name |
| `x-failure-message` | Truncated error message |

Inspect and requeue:

```bash
curl http://localhost:8081/debug/dlq
curl -X POST http://localhost:8081/debug/dlq/requeue -d '{"limit":10}'
```

## Observability during failures

Prometheus gauge `payment_failure_mode_active{mode="..."}` reflects the active
debug mode. Counters `payment_request_retries_total` and
`payment_request_dlq_total` track messaging-level failures.

Structured log events include `payment.request.failed`, `payment.request.dlq`,
and `payment.request.retry_scheduled`.

## When to use which

| Goal | Use |
| --- | --- |
| Demo card-specific declines | Sandbox tokens |
| Test marketplace retry logic | `provider_unavailable`, `timeout`, `publish_failure` |
| Test idempotent consumer | `duplicate_response` + same `idempotency_key` |
| Load / latency simulation | `processing_delay` |
| Reset demo environment | `POST /debug/reset` |

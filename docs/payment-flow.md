# Payment flow

This document describes the authorization → capture → refund lifecycle implemented
by the payment mock and how it maps to RabbitMQ messages.

Part of the StockFlow ecosystem:
[stockflow-market](https://github.com/Smiley-Alyx/stockflow-market),
[stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock),
[stockflow-payment-mock](https://github.com/Smiley-Alyx/stockflow-payment-mock),
[stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock).

In the full checkout scenario, [stockflow-market](https://github.com/Smiley-Alyx/stockflow-market)
coordinates inventory reservation ([stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock)),
payment (this service), and shipment creation
([stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock)).
Payment messages share the same `correlation_id` as sibling provider requests so
the market can link authorization, capture, and refund to a single order.

See [`architecture.md`](architecture.md#stockflow-ecosystem) for the ecosystem
overview.

## Lifecycle overview

```text
Marketplace                    Payment mock                         Marketplace
    │                               │                                    │
    │ authorization.requested       │                                    │
    │──────────────────────────────►│                                    │
    │                               │ reserve balance (if approved)      │
    │ authorization.approved/declined                                    │
    │◄──────────────────────────────│                                    │
    │                               │                                    │
    │ capture.requested             │                                    │
    │──────────────────────────────►│                                    │
    │                               │ finalize capture                   │
    │ capture.completed/failed      │                                    │
    │◄──────────────────────────────│                                    │
    │                               │                                    │
    │ refund.requested              │                                    │
    │──────────────────────────────►│                                    │
    │                               │ release balance (if completed)       │
    │ refund.completed/failed       │                                    │
    │◄──────────────────────────────│                                    │
```

Manual capture mode is used throughout: authorization holds funds on the sandbox
card balance; capture moves them to captured state; refund releases back to the card.

## Payment statuses

| Status | Meaning |
| --- | --- |
| `created` | Payment record exists, authorization not started |
| `authorization_pending` | Authorization attempt in progress |
| `authorized` | Funds reserved, ready for capture |
| `authorization_declined` | Authorization failed (terminal) |
| `capture_pending` | Capture attempt in progress |
| `captured` | Funds captured (terminal unless refunded) |
| `capture_failed` | Capture failed; authorization released (terminal) |
| `refund_pending` | Refund attempt in progress |
| `partially_refunded` | Some funds refunded, more may be refunded |
| `refunded` | Fully refunded (terminal) |
| `refund_failed` | Refund rejected (terminal) |

State transitions are enforced by `Payment::transitionTo()`.

## Message headers

Every request and response carries:

| Header | Rule |
| --- | --- |
| `message_id` | Unique per message instance |
| `correlation_id` | Same across the entire checkout flow |
| `causation_id` | Incoming request `message_id` on outbound events |
| `idempotency_key` | Caller-provided; scoped per operation |
| `schema_version` | `v1` |
| `occurred_at` | UTC ISO-8601 timestamp |
| `producer` | `stockflow-market` or `stockflow-payment-mock` |

See [`contracts/README.md`](../contracts/README.md) for full contract rules.

## Authorization

**Request:** `payment.authorization.requested.v1`

Required payload fields: `payment_id`, `order_id`, `customer_id`, `amount`,
`payment_method.token`, `capture_mode`.

**Outcomes:**

| Event | When |
| --- | --- |
| `payment.authorization.approved.v1` | Token valid, balance sufficient, no decline rule matched |
| `payment.authorization.declined.v1` | Insufficient funds, expired/blocked card, invalid token, etc. |

Decline reason codes are stable contract values, e.g. `INSUFFICIENT_FUNDS`,
`CARD_EXPIRED`, `CAPTURE_FAILED`.

On approval the sandbox balance is **reserved** (debited). On decline nothing is
debited.

## Capture

**Request:** `payment.capture.requested.v1`

Payment must be in `authorized` state. Amount is optional; defaults to the full
authorized amount.

**Outcomes:**

| Event | When |
| --- | --- |
| `payment.capture.completed.v1` | Capture succeeded |
| `payment.capture.failed.v1` | Sandbox capture failure or invalid transition |

On capture failure the reserved authorization balance is **released** back to the
sandbox card.

## Refund

**Request:** `payment.refund.requested.v1`

Payment must be `captured` or `partially_refunded`. Refund amount must not exceed
`refundableAmount`.

**Outcomes:**

| Event | When |
| --- | --- |
| `payment.refund.completed.v1` | Refund succeeded |
| `payment.refund.failed.v1` | Validation failure or sandbox refund failure |

Partial refunds leave the payment in `partially_refunded`; a full refund moves
to `refunded`.

## Idempotent retries

Scope: `(payment_id, operation, idempotency_key)`.

| Retry behavior | Domain | Outbound event |
| --- | --- | --- |
| Same key, same operation | Replays stored attempt | Republishes original event (`message_id` unchanged) |
| Same key, conflicting payload | — | `PublishedEventConflictException` → DLQ |

Example keys from contracts:

```text
idem_auth_pay_demo_001   → authorization
idem_cap_pay_demo_001    → capture
idem_ref_pay_demo_001    → refund
```

## Retry and DLQ (transient failures)

When processing throws a retryable error (e.g. simulated provider outage):

1. Message is acked and published to `stockflow.payment.requests.retry`.
2. Retry handler republishes to the main exchange after optional delay.
3. After `RABBITMQ_MAX_RETRY_ATTEMPTS`, message lands in DLQ with failure metadata.

Invalid payloads skip retry and go straight to DLQ via reject.

Manual recovery: `php artisan payment-mock:requeue-dlq` or `POST /debug/dlq/requeue`.

## Contract references

- AsyncAPI: [`contracts/asyncapi.yaml`](../contracts/asyncapi.yaml)
- Examples: [`contracts/examples/`](../contracts/examples/)
- JSON Schemas: [`contracts/messages/`](../contracts/messages/)

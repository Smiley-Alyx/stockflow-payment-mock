# Payment messaging contracts

This folder contains the AsyncAPI contract and JSON Schemas for RabbitMQ
integration between `stockflow-market` and `stockflow-payment-mock`.

## Layout

```text
contracts/
  asyncapi.yaml
  messages/
    common/
    payment.*.v1.json
  examples/
    payment.*.v1.json
```

## Message catalog

| Direction | Routing key | Producer | Consumer |
| --- | --- | --- | --- |
| Request | `payment.authorization.requested.v1` | marketplace | payment mock |
| Event | `payment.authorization.approved.v1` | payment mock | marketplace |
| Event | `payment.authorization.declined.v1` | payment mock | marketplace |
| Request | `payment.capture.requested.v1` | marketplace | payment mock |
| Event | `payment.capture.completed.v1` | payment mock | marketplace |
| Event | `payment.capture.failed.v1` | payment mock | marketplace |
| Request | `payment.refund.requested.v1` | marketplace | payment mock |
| Event | `payment.refund.completed.v1` | payment mock | marketplace |
| Event | `payment.refund.failed.v1` | payment mock | marketplace |

## RabbitMQ topology

| Resource | Name |
| --- | --- |
| Exchange | `stockflow.payment` |
| Dead-letter exchange | `stockflow.payment.dlx` |
| Request queue | `stockflow.payment.requests` |
| Retry queue | `stockflow.payment.requests.retry` |
| DLQ | `stockflow.payment.requests.dlq` |

Routing keys match message names exactly.

## Required headers

Every message must carry these AMQP headers or JSON metadata headers:

| Header | Description |
| --- | --- |
| `message_id` | Unique ID of this message instance |
| `correlation_id` | Business correlation ID for the payment flow |
| `causation_id` | `message_id` of the message that caused this one |
| `idempotency_key` | Caller-provided retry-safe key |
| `schema_version` | Payload schema version, currently `v1` |
| `occurred_at` | UTC timestamp in ISO-8601 |
| `producer` | Producing service name |

Schema: [`messages/common/message-headers.json`](messages/common/message-headers.json)

## Correlation and causation

Correlation is preserved end-to-end across authorization, capture, and refund.

```text
marketplace                         payment mock
    |  authorization.requested         |
    |  correlation_id = cor_123      |
    | ------------------------------>|
    |                                  |
    |  authorization.approved          |
    |  correlation_id = cor_123      |
    |  causation_id = incoming msg_id  |
    | <------------------------------|
    |                                  |
    |  capture.requested               |
    |  correlation_id = cor_123        |
    | ------------------------------>|
    |                                  |
    |  capture.completed               |
    |  correlation_id = cor_123        |
    |  causation_id = incoming msg_id  |
    | <------------------------------|
```

Rules for outgoing events from the payment mock:

1. Copy `correlation_id` from the incoming request unchanged.
2. Set `causation_id` to the incoming request `message_id`.
3. Generate a new unique `message_id` for every published event.
4. Reuse the incoming `idempotency_key` or derive a deterministic response key
   from it when publishing the outcome event.

## Idempotency

The payment mock treats `(payment_id, operation, idempotency_key)` as the
idempotency scope:

| Operation | Example key |
| --- | --- |
| Authorization | `idem_auth_pay_demo_001` |
| Capture | `idem_cap_pay_demo_001` |
| Refund | `idem_ref_pay_demo_001` |

Retry behavior:

- Same `idempotency_key` for the same payment and operation must return the same
  logical result.
- Sandbox balance must not be debited or refunded twice.
- Duplicate requests must not create duplicate attempts with conflicting outcomes.

## Sandbox safety

- Use predefined tokens such as `tok_approved_visa`.
- Never include PAN, CVV, or other raw card data in message payloads.
- Decline reason codes are stable contract values such as `INSUFFICIENT_FUNDS`
  and `CAPTURE_FAILED`.

## Examples

Full request/response examples with headers and payload live in
[`examples/`](examples/).

Happy path sequence:

1. `payment.authorization.requested.v1`
2. `payment.authorization.approved.v1`
3. `payment.capture.requested.v1`
4. `payment.capture.completed.v1`
5. `payment.refund.requested.v1`
6. `payment.refund.completed.v1`

Negative path examples:

- `payment.authorization.declined.v1`
- `payment.capture.failed.v1`
- `payment.refund.failed.v1`

## Validation

JSON Schemas are in [`messages/`](messages/). They can be used by producers and
consumers independently of the AsyncAPI document.

```bash
# Optional local validation with AsyncAPI CLI
asyncapi validate contracts/asyncapi.yaml
```

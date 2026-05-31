# Demo guide

Step-by-step scenarios for exploring the payment mock locally. All examples assume
Docker Compose is running:

```bash
make docker-up
```

Part of the StockFlow ecosystem:
[stockflow-market](https://github.com/Smiley-Alyx/stockflow-market),
[stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock),
[stockflow-payment-mock](https://github.com/Smiley-Alyx/stockflow-payment-mock),
[stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock).

This repository covers the **payment** boundary only. For a full checkout demo,
run the sibling mocks alongside [stockflow-market](https://github.com/Smiley-Alyx/stockflow-market)
and follow their READMEs for inventory reservation and shipment creation. Payment
scenarios here can be driven manually via RabbitMQ or through the market once
checkout integration is wired.

| Service | Local HTTP (typical) |
| --- | --- |
| [stockflow-market](https://github.com/Smiley-Alyx/stockflow-market) | gateway (see market README) |
| [stockflow-erp-mock](https://github.com/Smiley-Alyx/stockflow-erp-mock) | `http://localhost:8080` |
| **stockflow-payment-mock** (this repo) | `http://localhost:8081` |
| [stockflow-delivery-mock](https://github.com/Smiley-Alyx/stockflow-delivery-mock) | `http://localhost:8082` |

Payment mock endpoints for the scenarios below:

| Service | URL |
| --- | --- |
| Payment mock HTTP | http://localhost:8081 |
| RabbitMQ AMQP | localhost:5673 |
| RabbitMQ management | http://localhost:15673 (stockflow / stockflow) |

Enable debug endpoints in `.env` or Docker env:

```text
PAYMENT_MOCK_DEBUG_ENABLED=true
```

## 1. Inspect sandbox cards

```bash
curl -s http://localhost:8081/sandbox/cards | jq .
```

Pick a token for the scenarios below (default happy path: `tok_approved_visa`).

## 2. Happy path over HTTP (domain only)

The marketplace normally drives flows over RabbitMQ, but you can inspect state via
HTTP after messages are processed.

Reset demo data:

```bash
curl -X POST http://localhost:8081/debug/reset
```

After running the RabbitMQ happy-path scenario (section 3), inspect results:

```bash
curl -s http://localhost:8081/payments | jq .
curl -s http://localhost:8081/payment-attempts | jq .
```

## 3. Happy path over RabbitMQ

With the worker running (`make docker-up` starts `payment-mock-worker`), publish
messages to exchange `stockflow.payment`.

Example authorization request (use RabbitMQ management UI, `rabbitmqadmin`, or
your marketplace publisher):

```json
{
  "headers": {
    "message_id": "msg_demo_auth_001",
    "correlation_id": "cor_demo_checkout_001",
    "causation_id": "msg_checkout_created_001",
    "idempotency_key": "idem_demo_auth_001",
    "schema_version": "v1",
    "occurred_at": "2026-05-31T10:00:00Z",
    "producer": "stockflow-market"
  },
  "payload": {
    "payment_id": "pay_demo_001",
    "order_id": "ord_demo_001",
    "customer_id": "cust_demo_001",
    "amount": { "value": 12990, "currency": "EUR" },
    "payment_method": { "type": "card", "token": "tok_approved_visa" },
    "capture_mode": "manual"
  }
}
```

Routing key: `payment.authorization.requested.v1`

Expected outbound event: `payment.authorization.approved.v1` with the same
`correlation_id` and `idempotency_key`.

Then publish capture and refund requests using the examples in
[`contracts/examples/`](../contracts/examples/).

Or run the automated integration suite:

```bash
make test-integration
```

## 4. Insufficient funds

Use token `tok_insufficient_funds` with an amount greater than the card balance
(e.g. `50000` cents):

```json
"payment_method": { "type": "card", "token": "tok_insufficient_funds" },
"amount": { "value": 50000, "currency": "EUR" }
```

Expected: `payment.authorization.declined.v1` with `reason_code: INSUFFICIENT_FUNDS`.

## 5. Capture failure after successful auth

1. Authorize with `tok_capture_failure`.
2. Capture with the same `payment_id`.

Expected: authorization approved, then `payment.capture.failed.v1` with
`CAPTURE_FAILED`. Sandbox balance is released.

## 6. Global decline mode

```bash
curl -X POST http://localhost:8081/debug/failure-mode \
  -H 'Content-Type: application/json' \
  -d '{"mode":"always_decline"}'
```

Publish any authorization request with `tok_approved_visa` — it will still decline
until you reset:

```bash
curl -X POST http://localhost:8081/debug/reset
```

## 7. Provider degradation and retry

```bash
curl -X POST http://localhost:8081/debug/failure-mode \
  -H 'Content-Type: application/json' \
  -d '{"mode":"provider_unavailable"}'
```

Publish a request. The worker will schedule retries and eventually move the message
to DLQ after max attempts.

Inspect DLQ:

```bash
curl -s http://localhost:8081/debug/dlq | jq .
```

Requeue failed messages:

```bash
curl -X POST http://localhost:8081/debug/dlq/requeue \
  -H 'Content-Type: application/json' \
  -d '{"limit": 10}'
```

Switch back to normal mode before continuing demos.

## 8. Idempotent retry

Publish the **same** authorization request twice (same `payment_id` and
`idempotency_key`, different `message_id`). Both should produce outbound events
with the **same** result `message_id` and payload. Only one sandbox debit occurs.

Verify via HTTP:

```bash
curl -s http://localhost:8081/payments/pay_demo_001 | jq .
```

## 9. Metrics

```bash
curl -s http://localhost:8081/metrics | grep payment_requests_total
curl -s http://localhost:8081/metrics | grep payment_failure_mode_active
```

## 10. CI parity

Run the same checks as GitHub Actions locally:

```bash
make test
make docker-up   # if not already running
make test-integration
```

## Contract examples

Copy-paste ready message bodies:

- [`payment.authorization.requested.v1`](../contracts/examples/payment.authorization.requested.v1.json)
- [`payment.capture.requested.v1`](../contracts/examples/payment.capture.requested.v1.json)
- [`payment.refund.requested.v1`](../contracts/examples/payment.refund.requested.v1.json)

Full sequence diagrams and header rules: [`payment-flow.md`](payment-flow.md).

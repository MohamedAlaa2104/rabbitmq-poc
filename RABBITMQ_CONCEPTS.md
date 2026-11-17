# RabbitMQ Concepts

A practical guide to RabbitMQ, using the setup in this repo (Laravel producer → RabbitMQ → Symfony consumer) as the running example.

## 1. The big picture

```
Producer ──publish──▶ Exchange ──(binding / routing key)──▶ Queue ──deliver──▶ Consumer
```

A producer never sends directly to a queue. It publishes to an **exchange**, and the exchange routes the message to zero or more **queues** based on **bindings**. Consumers read from queues.

In this repo:

| Piece | Value |
|---|---|
| Producer | Laravel (`OrderEventPublisher`) |
| Exchange | `orders.exchange` (type `topic`) |
| Routing key | `order.created` |
| Binding | `orders.exchange` → `orders.created.queue` with key `order.created` |
| Queue | `orders.created.queue` |
| Consumer | Symfony command `app:orders:consume` |

## 2. Core concepts

### Producer
An application that publishes messages. It only knows the exchange name and a routing key, not who consumes.

### Consumer
An application that receives messages from a queue. Several consumers can read the same queue; each message goes to only one of them (competing consumers).

### Message
A body (here JSON) plus properties/headers: `content_type`, `delivery_mode` (2 = persistent), `headers`, `correlation_id`, `expiration` (TTL), `priority`, etc.

### Queue
A buffer that stores messages until a consumer takes them. Key properties:
- **durable**: the queue definition survives a broker restart.
- **exclusive**: usable by one connection only, deleted when it closes.
- **auto-delete**: deleted when the last consumer disconnects.
- **arguments**: TTL, max length, dead-letter exchange, queue type (classic/quorum), and so on.

Durable queue + persistent messages = messages survive a restart.

### Exchange
Receives messages from producers and routes them to queues. It stores nothing. If no queue matches, the message is dropped (unless you use the `mandatory` flag or an alternate exchange).

### Binding
A rule linking an exchange to a queue ("send messages like this to that queue"). It can carry a **binding key**.

### Routing key vs binding key
- **Routing key**: set by the producer on each message (`order.created`).
- **Binding key**: set when binding a queue to an exchange (`order.created`, or a pattern like `order.*`).

The exchange compares the message's routing key with each binding key, using rules that depend on the exchange type.

### Virtual host (vhost)
A logical namespace inside one broker, with its own exchanges, queues and permissions. This repo uses the default `/`. Use separate vhosts to isolate environments or teams.

### Connection and channel
- **Connection**: a TCP connection to the broker (expensive).
- **Channel**: a lightweight virtual connection inside it. Publish and consume on channels; use one channel per thread.

## 3. Exchange types

### Direct
Delivers to queues whose binding key **exactly equals** the routing key.
- Use for: task routing by type, e.g. `email`, `sms`, `pdf`.
- Example: key `invoice.pdf` goes only to the queue bound with `invoice.pdf`.

### Topic (used here)
Routing keys are dot-separated words. Binding keys can use wildcards:
- `*` matches exactly one word
- `#` matches zero or more words

| Binding key | Matches `order.created` | Matches `order.eu.cancelled` |
|---|---|---|
| `order.created` | yes | no |
| `order.*` | yes | no |
| `order.#` | yes | yes |
| `*.created` | yes | no |
| `#` | yes | yes |

Use for: event streams where different consumers want different subsets (`order.#` for analytics, `order.created` for emails).

### Fanout
Ignores the routing key and copies the message to **every** bound queue.
- Use for: broadcasts, cache invalidation, notifying many services of the same event.

### Headers
Routes on message header values instead of the routing key (`x-match: all|any`).
- Use for: routing on several attributes that don't fit a dotted key. Rarely needed.

### Default exchange
An unnamed direct exchange (`""`) that routes by queue name: the routing key equals the queue name. It is convenient for quick tests, but it ties producers to queue names. This repo deliberately uses a named exchange instead.

## 4. Message flow in this repo

1. `GET /orders/publish` makes Laravel declare `orders.exchange`, `orders.created.queue` and the binding (idempotent), then publish JSON with routing key `order.created`.
2. The topic exchange matches `order.created` against the binding key and puts the message in `orders.created.queue`.
3. The Symfony worker (`app:orders:consume`) takes a configured number of messages every N seconds, runs `OrderCreatedHandler`, and **acks** each one.

Example payload:
```json
{
  "event": "order.created",
  "version": "1.0",
  "id": "uuid",
  "occurred_at": "2026-11-16T21:18:26+00:00",
  "data": { "order_id": 441360, "total": 4100.19, "currency": "EGP" }
}
```

## 5. Reliability

### Acknowledgements
- **ack**: the consumer finished; RabbitMQ removes the message.
- **nack / reject**: the consumer failed. With `requeue=true` the message goes back to the queue; with `false` it is dropped or dead-lettered.
- If a consumer dies without acking, the message is redelivered. Make handlers **idempotent**, since a message can be delivered more than once (use the `id` field to deduplicate).

### Publisher confirms
The broker tells the producer a message was safely received. Enable them when losing a message is unacceptable. This repo doesn't use them yet.

### Durability checklist
Durable exchange + durable queue + persistent messages (`delivery_mode=2`). This repo does all three.

### Prefetch (QoS)
Limits how many unacked messages a consumer holds at once. Low values give fair distribution; high values give throughput.

### Dead-letter exchange (DLX)
Messages that are rejected, expire (TTL) or overflow a queue's max length can be re-routed to a DLX. Use it for:
- a "failed messages" queue for inspection and replay
- **retry with delay**: dead-letter into a queue with a TTL, which dead-letters back to the main queue

### TTL
Per-message (`expiration`) or per-queue (`x-message-ttl`) lifetime. Expired messages are dropped or dead-lettered.

## 6. Queue types

- **Classic**: the default, simple, fine for most single-node setups.
- **Quorum**: replicated and durable, the recommended choice for important data on clusters.
- **Stream**: append-only log with replay, for high-throughput fan-out where consumers re-read history.

## 7. Messaging patterns and use cases

| Pattern | Setup | Example |
|---|---|---|
| **Work queue** | One queue, many consumers | Resize images, send emails in parallel |
| **Publish/subscribe** | Fanout exchange, one queue per subscriber | One `order.created` event feeds billing, email and analytics |
| **Routing** | Direct exchange, keyed bindings | Send `error` logs to alerting and `info` logs to storage |
| **Topics** | Topic exchange, wildcard bindings | `order.#`, `*.created`, per-region subscriptions |
| **RPC** | Request queue + `reply_to` + `correlation_id` | Synchronous-style call between services |
| **Delayed / retry** | TTL + DLX | Retry a failed webhook after 30 s |

Typical reasons to use RabbitMQ:
- decouple services so the producer doesn't wait for slow work
- absorb traffic spikes (the queue buffers while consumers catch up)
- run background jobs reliably with retries
- fan one event out to many services
- throttle work, as this repo's consumer does (N messages every M seconds)

Add a consumer to this repo without touching the producer: create a new queue, bind it to `orders.exchange` with `order.created` (or `order.#`), and it receives its own copy of every event.

## 8. Using the management dashboard

Open http://localhost:15672 (guest / guest).

- **Overview**: message rates and totals.
- **Exchanges**: see `orders.exchange`, its type, and its bindings.
- **Queues**: ready vs unacked counts for `orders.created.queue`. Open a queue to inspect bindings, publish a test message, or **Get messages** to peek (use "Nack, requeue true" so you don't consume them).
- **Connections / Channels**: who is connected and their prefetch.
- **Admin**: users, vhosts, permissions, policies.

Handy API checks:
```bash
curl -u guest:guest http://localhost:15672/api/queues
curl -u guest:guest http://localhost:15672/api/bindings
```

## 9. Operating this repo

```bash
docker compose up -d --build                           # rabbitmq + laravel + symfony
curl localhost:8000/orders/publish                     # publish 1 message
curl localhost:8000/orders/publish/100                 # publish 100 messages
docker compose --profile worker up -d consumer-worker  # start the throttled consumer
docker compose logs -f consumer-worker
```

Consumer throughput is set in `consumer/config/services.yaml` (or via env vars):
`ORDERS_CONSUME_BATCH_SIZE` (messages per cycle) and `ORDERS_CONSUME_INTERVAL` (seconds between cycles).

## 10. Glossary

| Term | Meaning |
|---|---|
| AMQP | The protocol RabbitMQ speaks (0-9-1), port 5672 |
| Ready | Messages waiting in a queue |
| Unacked | Delivered to a consumer, not yet acknowledged |
| Competing consumers | Several consumers sharing one queue |
| Idempotent | Processing the same message twice has the same effect as once |
| DLX | Dead-letter exchange, where rejected or expired messages are re-routed |
| Prefetch | Max unacked messages per consumer |
| Vhost | Isolated namespace within a broker |

<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes `order.created` events as JSON to a dedicated topic exchange
 * (not RabbitMQ's default exchange / default queue).
 */
class OrderEventPublisher
{
    public const EXCHANGE = 'orders.exchange';
    public const QUEUE = 'orders.created.queue';
    public const ROUTING_KEY = 'order.created';
    public const EVENT = 'order.created';
    public const VERSION = '1.0';

    /** @return int number of messages published */
    public function publish(int $count = 1): int
    {
        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', 'rabbitmq'),
            (int) env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest'),
            env('RABBITMQ_VHOST', '/'),
        );
        $channel = $connection->channel();

        // Custom durable topology
        $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $channel->queue_declare(self::QUEUE, false, true, false, false);
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        for ($i = 0; $i < $count; $i++) {
            $channel->basic_publish(
                new AMQPMessage(
                    json_encode($this->payload(), JSON_THROW_ON_ERROR),
                    ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
                ),
                self::EXCHANGE,
                self::ROUTING_KEY
            );
        }

        $channel->close();
        $connection->close();

        return $count;
    }

    private function payload(): array
    {
        $items = [];
        for ($i = 0, $n = random_int(1, 5); $i < $n; $i++) {
            $items[] = [
                'sku' => strtoupper(Str::random(8)),
                'quantity' => random_int(1, 10),
                'price' => round(random_int(500, 50000) / 100, 2),
            ];
        }

        return [
            'event' => self::EVENT,
            'version' => self::VERSION,
            'id' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_id' => random_int(100000, 999999),
                'customer' => ['id' => random_int(1, 5000), 'email' => fake()->safeEmail()],
                'items' => $items,
                'total' => round(array_sum(array_map(fn ($i) => $i['quantity'] * $i['price'], $items)), 2),
                'currency' => fake()->randomElement(['USD', 'EUR', 'EGP']),
            ],
        ];
    }
}

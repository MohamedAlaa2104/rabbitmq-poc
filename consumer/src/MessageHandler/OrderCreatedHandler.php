<?php

namespace App\MessageHandler;

use App\Message\OrderCreated;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OrderCreatedHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(OrderCreated $message): void
    {
        $this->logger->info('order.created consumed', [
            'id' => $message->id,
            'version' => $message->version,
            'order_id' => $message->data['order_id'] ?? null,
            'total' => $message->data['total'] ?? null,
        ]);
    }
}

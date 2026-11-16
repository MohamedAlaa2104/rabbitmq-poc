<?php

namespace App\Messenger;

use App\Message\OrderCreated;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** Decodes the producer's plain JSON (`order.created`) into an OrderCreated message. */
final class OrderCreatedSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = json_decode($encodedEnvelope['body'] ?? '', true);

        if (!is_array($body) || ($body['event'] ?? null) !== 'order.created') {
            throw new MessageDecodingFailedException('Unsupported or invalid message.');
        }

        return new Envelope(new OrderCreated(
            $body['id'],
            $body['version'],
            $body['occurred_at'],
            $body['data'],
        ));
    }

    public function encode(Envelope $envelope): array
    {
        $m = $envelope->getMessage();

        return ['body' => json_encode([
            'event' => 'order.created',
            'version' => $m->version,
            'id' => $m->id,
            'occurred_at' => $m->occurredAt,
            'data' => $m->data,
        ]), 'headers' => ['Content-Type' => 'application/json']];
    }
}

<?php

namespace App\Message;

final class OrderCreated
{
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $occurredAt,
        public readonly array $data,
    ) {
    }
}

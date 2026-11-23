<?php

namespace App\Events\Widget;

class ReplyToken extends WidgetReplyEvent
{
    public function __construct(string $conversationUlid, public readonly string $delta)
    {
        parent::__construct($conversationUlid);
    }

    public function broadcastAs(): string
    {
        return 'reply.token';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['delta' => $this->delta];
    }
}

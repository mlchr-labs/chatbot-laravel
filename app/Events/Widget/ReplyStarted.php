<?php

namespace App\Events\Widget;

class ReplyStarted extends WidgetReplyEvent
{
    public function broadcastAs(): string
    {
        return 'reply.started';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}

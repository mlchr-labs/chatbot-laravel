<?php

namespace App\Events\Widget;

class ReplyFailed extends WidgetReplyEvent
{
    public function __construct(string $conversationUlid, public readonly string $message)
    {
        parent::__construct($conversationUlid);
    }

    public function broadcastAs(): string
    {
        return 'reply.failed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}

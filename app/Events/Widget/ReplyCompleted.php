<?php

namespace App\Events\Widget;

class ReplyCompleted extends WidgetReplyEvent
{
    public function __construct(
        string $conversationUlid,
        public readonly string $messageUlid,
        public readonly string $content,
    ) {
        parent::__construct($conversationUlid);
    }

    public function broadcastAs(): string
    {
        return 'reply.completed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->messageUlid, 'content' => $this->content];
    }
}

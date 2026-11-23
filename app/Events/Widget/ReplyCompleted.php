<?php

namespace App\Events\Widget;

/**
 * Carries only the message id. Reverb rejects HTTP requests above max_request_size (10 KB by default), and a long
 * reply, JSON escaped twice by the Pusher envelope, can exceed it. The widget already has the text from the
 * reply.token events and keeps what it streamed; the persisted message is returned when the session is resumed.
 */
class ReplyCompleted extends WidgetReplyEvent
{
    public function __construct(
        string $conversationUlid,
        public readonly string $messageUlid,
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
        return ['id' => $this->messageUlid];
    }
}

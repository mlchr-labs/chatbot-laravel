<?php

namespace App\Events\Widget;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for everything sent to the visitor on private channel "widget.conversation.{ulid}".
 * Broadcast synchronously: queueing tokens would reorder them and add latency.
 */
abstract class WidgetReplyEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly string $conversationUlid) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('widget.conversation.'.$this->conversationUlid)];
    }
}

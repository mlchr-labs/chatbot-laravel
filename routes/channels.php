<?php

use App\Auth\WidgetVisitor;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user instanceof User && (string) $user->id === (string) $id;
}, ['guards' => ['web']]);

// Visitors of the embeddable widget. Only the "widget" guard is consulted, so a panel session never
// authorizes this channel and a widget token never authorizes the panel ones.
Broadcast::channel('widget.conversation.{ulid}', function ($visitor, string $ulid) {
    return $visitor instanceof WidgetVisitor && hash_equals($visitor->conversation->ulid, $ulid);
}, ['guards' => ['widget']]);

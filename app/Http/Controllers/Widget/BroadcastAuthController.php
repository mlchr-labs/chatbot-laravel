<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class BroadcastAuthController extends Controller
{
    /**
     * Pusher-protocol channel auth for the visitor. The channel callbacks in routes/channels.php decide
     * access: the widget channel only accepts the "widget" guard, so session users never get in here.
     */
    public function __invoke(Request $request): mixed
    {
        return Broadcast::auth($request);
    }
}

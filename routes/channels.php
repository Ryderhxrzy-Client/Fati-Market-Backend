<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The required callback is used to authorize if an
| authenticated user can listen to the channel.
|
*/

// Authorize any authenticated user to listen to item messages channel
Broadcast::channel('item.{itemId}', function ($user) {
    return (bool) $user;
});

// Authorize any authenticated user to listen to conversation channels
Broadcast::channel('conversation.{userId1}.{userId2}', function ($user) {
    return (bool) $user;
});

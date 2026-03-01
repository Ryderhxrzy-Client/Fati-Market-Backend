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

// Authorize users to listen to conversation channels (both participants can access)
Broadcast::channel('conversation.{userId1}.{userId2}', function ($user, $userId1, $userId2) {
    return (int) $user->user_id === (int) $userId1 || (int) $user->user_id === (int) $userId2;
});

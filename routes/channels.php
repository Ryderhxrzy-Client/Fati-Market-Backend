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

// Authorize user to listen to item messages channel
Broadcast::channel('item.{itemId}', function ($user, $itemId) {
    return true;
});

// Authorize users to listen to conversation channels
Broadcast::channel('conversation.{userId1}.{userId2}', function ($user, $userId1, $userId2) {
    return $user->user_id === (int)$userId1 || $user->user_id === (int)$userId2;
});

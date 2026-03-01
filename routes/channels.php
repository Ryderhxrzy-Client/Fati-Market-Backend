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
    // Allow access if user is one of the participants
    // Using getKey() to get the primary key value regardless of column name
    $userKey = (int) $user->getKey();
    $user1 = (int) $userId1;
    $user2 = (int) $userId2;

    return $userKey === $user1 || $userKey === $user2;
});

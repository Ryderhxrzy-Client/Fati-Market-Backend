<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $itemId;
    public $senderId;
    public $receiverId;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->itemId = $message->item_id;
        $this->senderId = $message->sender_id;
        $this->receiverId = $message->receiver_id;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // Broadcast to item messages channel
            new PrivateChannel('item.' . $this->itemId),
            // Broadcast to conversation channel between users
            new PrivateChannel('conversation.' . $this->senderId . '.' . $this->receiverId),
            new PrivateChannel('conversation.' . $this->receiverId . '.' . $this->senderId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->message_id,
            'item_id' => $this->message->item_id,
            'item_title' => $this->message->item?->title,
            'sender_id' => $this->message->sender_id,
            'sender_email' => $this->message->sender->email,
            'sender_name' => $this->message->sender->studentInfo?->first_name . ' ' . $this->message->sender->studentInfo?->last_name,
            'sender_profile_picture' => $this->message->sender->studentInfo?->profile_picture,
            'receiver_id' => $this->message->receiver_id,
            'receiver_email' => $this->message->receiver->email,
            'receiver_name' => $this->message->receiver->studentInfo?->first_name . ' ' . $this->message->receiver->studentInfo?->last_name,
            'receiver_profile_picture' => $this->message->receiver->studentInfo?->profile_picture,
            'message' => $this->message->message,
            'sent_at' => $this->message->sent_at,
        ];
    }

    /**
     * Get the event name to broadcast.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}

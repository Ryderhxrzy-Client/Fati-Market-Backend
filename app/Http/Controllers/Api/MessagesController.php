<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessagesController extends Controller
{
    /**
     * Send a message for an item
     * POST /api/messages/{item_id}
     */
    public function sendMessage(Request $request, $itemId)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'receiver_id' => ['required', 'integer', 'exists:users,user_id'],
                'message' => ['required', 'string', 'max:1000'],
            ]);

            // Check if receiver exists
            $receiver = User::where('user_id', $validated['receiver_id'])->first();
            if (!$receiver) {
                return response()->json([
                    'message' => 'Receiver not found',
                ], 404);
            }

            // Create message
            $newMessage = Message::create([
                'item_id' => $itemId,
                'sender_id' => $request->user()->user_id,
                'receiver_id' => $validated['receiver_id'],
                'message' => $validated['message'],
                'sent_at' => now(),
            ]);

            Log::info('Message sent', [
                'sender_id' => $request->user()->user_id,
                'receiver_id' => $validated['receiver_id'],
                'item_id' => $itemId,
                'message_id' => $newMessage->message_id,
            ]);

            return response()->json([
                'message' => 'Message sent successfully',
                'data' => [
                    'message_id' => $newMessage->message_id,
                    'item_id' => $newMessage->item_id,
                    'sender_id' => $newMessage->sender_id,
                    'receiver_id' => $newMessage->receiver_id,
                    'message' => $newMessage->message,
                    'sent_at' => $newMessage->sent_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error sending message', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to send message',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all messages for an item (current user only)
     * GET /api/messages/{item_id}
     */
    public function getMessagesByItem(Request $request, $itemId)
    {
        try {
            $userId = $request->user()->user_id;

            // Get messages for this item where current user is sender or receiver
            $messages = Message::with([
                'sender' => function ($query) {
                    $query->select('user_id', 'email');
                },
                'sender.studentInfo' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'profile_picture');
                },
                'receiver' => function ($query) {
                    $query->select('user_id', 'email');
                },
                'receiver.studentInfo' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'profile_picture');
                },
                'item' => function ($query) {
                    $query->select('item_id', 'title');
                }
            ])
                ->where('item_id', $itemId)
                ->where(function ($query) use ($userId) {
                    $query->where('sender_id', $userId)
                        ->orWhere('receiver_id', $userId);
                })
                ->orderBy('sent_at', 'asc')
                ->get()
                ->map(function ($msg) {
                    return [
                        'message_id' => $msg->message_id,
                        'item_id' => $msg->item_id,
                        'item_title' => $msg->item?->title,
                        'sender_id' => $msg->sender_id,
                        'sender_email' => $msg->sender->email,
                        'sender_name' => $msg->sender->studentInfo?->first_name . ' ' . $msg->sender->studentInfo?->last_name,
                        'sender_profile_picture' => $msg->sender->studentInfo?->profile_picture,
                        'receiver_id' => $msg->receiver_id,
                        'receiver_email' => $msg->receiver->email,
                        'receiver_name' => $msg->receiver->studentInfo?->first_name . ' ' . $msg->receiver->studentInfo?->last_name,
                        'receiver_profile_picture' => $msg->receiver->studentInfo?->profile_picture,
                        'message' => $msg->message,
                        'sent_at' => $msg->sent_at,
                    ];
                });

            return response()->json([
                'message' => 'Messages retrieved successfully',
                'data' => $messages,
                'count' => $messages->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting messages', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve messages',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all conversations for the logged-in user
     * GET /api/conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $userId = $request->user()->user_id;

            // Get all users this user has messaged (as sender or receiver)
            $conversations = Message::where('sender_id', $userId)
                ->orWhere('receiver_id', $userId)
                ->with([
                    'sender' => function ($query) {
                        $query->select('user_id', 'email');
                    },
                    'sender.studentInfo' => function ($query) {
                        $query->select('user_id', 'first_name', 'last_name', 'profile_picture');
                    },
                    'receiver' => function ($query) {
                        $query->select('user_id', 'email');
                    },
                    'receiver.studentInfo' => function ($query) {
                        $query->select('user_id', 'first_name', 'last_name', 'profile_picture');
                    },
                    'item' => function ($query) {
                        $query->select('item_id', 'title');
                    }
                ])
                ->orderBy('sent_at', 'desc')
                ->get()
                ->groupBy(function ($message) use ($userId) {
                    // Group by the other user (not current user)
                    return $message->sender_id === $userId ? $message->receiver_id : $message->sender_id;
                })
                ->map(function ($messages, $otherUserId) use ($userId) {
                    $latestMessage = $messages->first();
                    $otherUser = $latestMessage->sender_id === $userId ? $latestMessage->receiver : $latestMessage->sender;

                    return [
                        'other_user_id' => $otherUserId,
                        'other_user_email' => $otherUser->email,
                        'first_name' => $otherUser->studentInfo?->first_name,
                        'last_name' => $otherUser->studentInfo?->last_name,
                        'profile_picture' => $otherUser->studentInfo?->profile_picture,
                        'item_id' => $latestMessage->item_id,
                        'item_title' => $latestMessage->item?->title,
                        'latest_message' => $latestMessage->message,
                        'last_message_at' => $latestMessage->sent_at,
                        'message_count' => $messages->count(),
                    ];
                })
                ->values();

            return response()->json([
                'message' => 'Conversations retrieved successfully',
                'data' => $conversations,
                'count' => $conversations->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting conversations', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve conversations',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get messages between two users
     * GET /api/conversations/{user_id}
     */
    public function getConversationWithUser(Request $request, $otherUserId)
    {
        try {
            $currentUserId = $request->user()->user_id;

            // Get all messages between these two users
            $messages = Message::where(function ($query) use ($currentUserId, $otherUserId) {
                $query->where('sender_id', $currentUserId)
                    ->where('receiver_id', $otherUserId);
            })
                ->orWhere(function ($query) use ($currentUserId, $otherUserId) {
                    $query->where('sender_id', $otherUserId)
                        ->where('receiver_id', $currentUserId);
                })
                ->with([
                    'sender' => function ($query) {
                        $query->select('user_id', 'email');
                    },
                    'receiver' => function ($query) {
                        $query->select('user_id', 'email');
                    }
                ])
                ->orderBy('sent_at', 'asc')
                ->get()
                ->map(function ($msg) {
                    return [
                        'message_id' => $msg->message_id,
                        'sender_id' => $msg->sender_id,
                        'sender_email' => $msg->sender->email,
                        'receiver_id' => $msg->receiver_id,
                        'receiver_email' => $msg->receiver->email,
                        'message' => $msg->message,
                        'sent_at' => $msg->sent_at,
                    ];
                });

            return response()->json([
                'message' => 'Conversation retrieved successfully',
                'data' => $messages,
                'count' => $messages->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting conversation', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve conversation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a message
     * DELETE /api/messages/{message_id}
     */
    public function deleteMessage(Request $request, $messageId)
    {
        try {
            $message = Message::where('message_id', $messageId)->first();

            if (!$message) {
                return response()->json([
                    'message' => 'Message not found',
                ], 404);
            }

            // Only allow sender to delete their own message
            if ($message->sender_id !== $request->user()->user_id) {
                return response()->json([
                    'message' => 'Unauthorized. You can only delete your own messages.',
                ], 403);
            }

            $message->delete();

            Log::info('Message deleted', [
                'message_id' => $messageId,
                'user_id' => $request->user()->user_id,
            ]);

            return response()->json([
                'message' => 'Message deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error deleting message', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to delete message',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

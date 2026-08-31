<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemPresenter;
use App\Http\Resources\TransactionPresenter;
use App\Models\Item;
use App\Models\Message;
use App\Models\User;
use App\Services\FcmService;
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

            // Reload message with relationships
            $newMessage->load(['sender.studentInfo', 'receiver.studentInfo', 'item']);

            // Push the same chat content to the receiver's registered devices.
            app(FcmService::class)->sendChatMessage($newMessage);

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
                    'item_title' => $newMessage->item?->title,
                    'item_status' => $newMessage->item?->status,
                    'sender_id' => $newMessage->sender_id,
                    'receiver_id' => $newMessage->receiver_id,
                    'message' => $newMessage->message,
                    'kind' => $newMessage->kind ?? Message::KIND_TEXT,
                    'transaction_id' => null,
                    'order' => null,
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

    /** POST /api/messages/{message_id}/read */
    public function markRead(Request $request, $messageId)
    {
        $updated = Message::where('message_id', $messageId)
            ->where('receiver_id', $request->user()->user_id)
            ->update(['is_read' => true]);

        return response()->json(['message' => $updated ? 'Message marked as read' : 'Message not found']);
    }

    /**
     * Get all messages for an item (current user only)
     * GET /api/messages/{item_id}
     */
    public function getMessagesByItem(Request $request, $itemId)
    {
        try {
            $userId = $request->user()->user_id;
            $otherUserId = $request->query('other_user_id');

            // Mark all unread messages as read for current user (receiver)
            $updateQuery = Message::where('item_id', $itemId)
                ->where('receiver_id', $userId)
                ->where('is_read', 0);
                
            if ($otherUserId) {
                $updateQuery->where('sender_id', $otherUserId);
            }
            
            $updateQuery->update(['is_read' => true]);

            // Get messages for this item where current user is sender or receiver
            $messageQuery = Message::with([
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
                    $query->select('item_id', 'title', 'status');
                },
                'item.photos' => function ($query) {
                    $query->select('item_id', 'photo_url');
                },
                // An order message is rendered as a card, which needs the
                // order itself - its live payment status included.
                'transaction.item.photos',
                'transaction.buyer.studentInfo',
            ])->where('item_id', $itemId);

            if ($otherUserId) {
                $messageQuery->where(function ($query) use ($userId, $otherUserId) {
                    $query->where(function ($q) use ($userId, $otherUserId) {
                        $q->where('sender_id', $userId)->where('receiver_id', $otherUserId);
                    })->orWhere(function ($q) use ($userId, $otherUserId) {
                        $q->where('sender_id', $otherUserId)->where('receiver_id', $userId);
                    });
                });
            } else {
                $messageQuery->where(function ($query) use ($userId) {
                    $query->where('sender_id', $userId)
                        ->orWhere('receiver_id', $userId);
                });
            }

            // Admin sees the order the way the transaction screen shows it,
            // actions included, so approving in chat and approving on the
            // orders screen are the same thing. The buyer sees their own view.
            $isAdmin = $request->user()->role === User::ROLE_ADMIN;

            $rows = $messageQuery->orderBy('sent_at', 'asc')->get();

            // The listing card behind an "item_listed" message. Conversations
            // are scoped per item, so one lookup serves the whole thread.
            // Admin gets the admin view (with the review prices); the seller
            // gets their own; anyone else gets no card.
            $itemCard = null;

            if ($rows->contains(fn ($m) => ($m->kind ?? '') === Message::KIND_ITEM_LISTED)) {
                $listedItem = Item::with(['photos', 'seller'])->find($itemId);

                if ($listedItem !== null) {
                    $itemCard = match (true) {
                        $isAdmin => ItemPresenter::forAdmin($listedItem),
                        $listedItem->seller_id === $userId => ItemPresenter::forSeller($listedItem),
                        default => null,
                    };
                }
            }

            $messages = $rows
                ->map(function ($msg) use ($isAdmin, $itemCard) {
                    return [
                        'message_id' => $msg->message_id,
                        'item_id' => $msg->item_id,
                        'item_title' => $msg->item?->title,
                        'item_status' => $msg->item?->status,
                        'item_photo' => $msg->item?->photos->first()?->photo_url,
                        'sender_id' => $msg->sender_id,
                        'sender_email' => $msg->sender->email,
                        'sender_name' => $msg->sender->studentInfo?->first_name . ' ' . $msg->sender->studentInfo?->last_name,
                        'sender_profile_picture' => $msg->sender->studentInfo?->profile_picture,
                        'receiver_id' => $msg->receiver_id,
                        'receiver_email' => $msg->receiver->email,
                        'receiver_name' => $msg->receiver->studentInfo?->first_name . ' ' . $msg->receiver->studentInfo?->last_name,
                        'receiver_profile_picture' => $msg->receiver->studentInfo?->profile_picture,
                        'message' => $msg->message,
                        'kind' => $msg->kind ?? Message::KIND_TEXT,
                        'transaction_id' => $msg->transaction_id,
                        // The state this line recorded, so a card shows its
                        // own moment rather than the order's latest.
                        'payment_status_at' => $msg->payment_status_at,
                        'order_status_at' => $msg->order_status_at,
                        'order' => $msg->transaction === null ? null : ($isAdmin
                            ? TransactionPresenter::forAdmin($msg->transaction)
                            : TransactionPresenter::forBuyer($msg->transaction)),
                        'item_card' => $msg->kind === Message::KIND_ITEM_LISTED ? $itemCard : null,
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
                        // acquisition_price rides along so the list can say
                        // "Offer accepted" without a per-item lookup.
                        $query->select('item_id', 'title', 'status', 'acquisition_price', 'seller_id');
                    },
                    'item.photos' => function ($query) {
                        $query->select('item_id', 'photo_url');
                    }
                ])
                ->orderBy('sent_at', 'desc')
                ->get()
                ->groupBy(function ($message) use ($userId) {
                    // Group by the other user AND item (separate conversations per item)
                    $otherUserId = $message->sender_id === $userId ? $message->receiver_id : $message->sender_id;
                    return $otherUserId . '_' . $message->item_id;
                })
                ->map(function ($messages, $groupKey) use ($userId) {
                    $latestMessage = $messages->first();
                    $otherUser = $latestMessage->sender_id === $userId ? $latestMessage->receiver : $latestMessage->sender;

                    // Count unread messages for this conversation
                    $unreadCount = $messages->filter(function ($msg) use ($userId) {
                        return $msg->receiver_id === $userId && !$msg->is_read;
                    })->count();

                    return [
                        'other_user_id' => $otherUser->user_id,
                        'other_user_email' => $otherUser->email,
                        'first_name' => $otherUser->studentInfo?->first_name,
                        'last_name' => $otherUser->studentInfo?->last_name,
                        'profile_picture' => $otherUser->studentInfo?->profile_picture,
                        'item_id' => $latestMessage->item_id,
                        'item_title' => $latestMessage->item?->title,
                        'item_status' => $latestMessage->item?->status,
                        'item_offer_accepted' => $latestMessage->item?->acquisition_price !== null,
                        'item_seller_id' => $latestMessage->item?->seller_id,
                        'item_photo' => $latestMessage->item?->photos->first()?->photo_url,
                        'latest_message' => $latestMessage->message,
                        'last_message_at' => $latestMessage->sent_at,
                        'message_count' => $messages->count(),
                        'unread_count' => $unreadCount,
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
                    },
                    'item' => function ($query) {
                        $query->select('item_id', 'title', 'status');
                    },
                    'item.photos' => function ($query) {
                        $query->select('item_id', 'photo_url');
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
                        'item_id' => $msg->item_id,
                        'item_title' => $msg->item?->title,
                        'item_status' => $msg->item?->status,
                        'item_photo' => $msg->item?->photos->first()?->photo_url,
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\FcmService;
use Illuminate\Http\Request;

class ChatNotificationsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['after_id' => ['sometimes', 'required', 'integer', 'min:0']]);
        $query = Message::where('receiver_id', $request->user()->user_id);
        // The first request establishes a cursor without replaying historical chats.
        if (! array_key_exists('after_id', $data)) {
            return response()->json(['data' => ['cursor' => (int) $query->max('message_id'), 'messages' => []]])
                ->header('Cache-Control', 'no-store');
        }

        // Advance past read/system messages too, but show only new unread chat text and order updates.
        $messages = $query->where('message_id', '>', $data['after_id'])
            ->orderBy('message_id')->limit(50)->with(['sender.studentInfo', 'item'])->get();
        $payloads = $messages->filter(fn ($message) => ! $message->is_read && ($message->kind === null || $message->kind === Message::KIND_TEXT || $message->kind === Message::KIND_ORDER_UPDATE))
            ->map(function ($message) {
                if ($message->kind === Message::KIND_ORDER_UPDATE) {
                    $item = $message->item;
                    return [
                        'type' => 'order_update',
                        'message_id' => (string) $message->message_id,
                        'item_id' => (string) $message->item_id,
                        'item_title' => (string) ($item?->title ?? ''),
                        'sender_id' => (string) $message->sender_id,
                        'recipient_id' => (string) $message->receiver_id,
                        'title' => 'Order update',
                        'body' => (string) $message->message,
                        'transaction_id' => (string) ($message->transaction_id ?? ''),
                    ];
                }
                return FcmService::chatData($message);
            })->values();

        return response()->json(['data' => [
            'cursor' => (int) ($messages->last()?->message_id ?? $data['after_id']),
            'messages' => $payloads,
        ]])->header('Cache-Control', 'no-store');
    }
}

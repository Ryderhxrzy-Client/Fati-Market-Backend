<?php

namespace Tests\Feature;

use App\Models\FcmDeviceToken;
use App\Models\Message;
use App\Services\FcmService;
use Illuminate\Support\Facades\Http;

class ChatNotificationsTest extends MarketplaceTestCase
{
    public function test_feed_is_authenticated_scoped_and_does_not_mark_messages_read(): void
    {
        $this->getJson('/api/notifications/chat')->assertUnauthorized();
        $sender = $this->student();
        $receiver = $this->student();
        $other = $this->student();
        $item = $this->publishedItem();
        $create = fn ($to, $text, $read = false) => Message::create([
            'item_id' => $item->item_id, 'sender_id' => $sender->user_id,
            'receiver_id' => $to->user_id, 'message' => $text, 'is_read' => $read,
            'kind' => Message::KIND_TEXT, 'sent_at' => now(),
        ]);
        $old = $create($receiver, 'Old chat');
        $this->actingAs($receiver)->getJson('/api/notifications/chat')
            ->assertOk()->assertJsonPath('data.cursor', $old->message_id)->assertJsonCount(0, 'data.messages');
        $new = $create($receiver, 'New chat');
        $create($other, 'Private other user chat');
        $read = $create($receiver, 'Already read', true);
        $this->getJson('/api/notifications/chat?after_id='.$old->message_id)
            ->assertOk()->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.message', 'New chat')
            ->assertJsonPath('data.messages.0.recipient_id', (string) $receiver->user_id)
            ->assertJsonPath('data.cursor', $read->message_id);
        $this->assertFalse((bool) $new->fresh()->is_read);
        $this->getJson('/api/notifications/chat?after_id=-1')->assertUnprocessable();
    }

    public function test_sending_a_chat_pushes_to_every_receiver_device(): void
    {
        $sender = $this->student();
        $receiver = $this->student();
        $item = $this->publishedItem();
        foreach (['phone-one', 'phone-two'] as $token) {
            FcmDeviceToken::create(['user_id' => $receiver->user_id, 'token' => $token, 'token_hash' => hash('sha256', $token)]);
        }
        config(['services.fcm.project_id' => 'test-project']);
        $this->app->instance(FcmService::class, new class extends FcmService
        {
            protected function accessToken(): string
            {
                return 'test-oauth';
            }
        });
        Http::preventStrayRequests();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'accepted'])]);
        $this->actingAs($sender)->postJson('/api/messages/'.$item->item_id, [
            'receiver_id' => $receiver->user_id, 'message' => 'A real chat event',
        ])->assertSuccessful();
        Http::assertSentCount(2);
        foreach (['phone-one', 'phone-two'] as $token) {
            Http::assertSent(fn ($request) => $request['message']['token'] === $token
                && $request['message']['data']['type'] === 'chat_message'
                && $request['message']['data']['message'] === 'A real chat event'
                && $request['message']['data']['recipient_id'] === (string) $receiver->user_id
                && $request['message']['android']['priority'] === 'HIGH');
        }
    }
}

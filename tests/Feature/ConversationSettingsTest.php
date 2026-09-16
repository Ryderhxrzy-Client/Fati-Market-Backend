<?php

namespace Tests\Feature;

use App\Models\ConversationSetting;
use App\Models\Message;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Replies, and the per-person housekeeping of a thread: pin, archive,
 * rename, clear.
 */
class ConversationSettingsTest extends MarketplaceTestCase
{
    private function chat(User $from, User $to, int $itemId, string $text, array $extra = []): Message
    {
        return Message::create(array_merge([
            'item_id' => $itemId,
            'sender_id' => $from->user_id,
            'receiver_id' => $to->user_id,
            'message' => $text,
            'kind' => Message::KIND_TEXT,
            'sent_at' => now(),
        ], $extra));
    }

    #[Test]
    public function a_message_can_answer_another_one_in_the_same_thread(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $item = $this->publishedItem();
        $asked = $this->chat($student, $admin, $item->item_id, 'Available pa po?');

        $sent = $this->actingAs($admin)->postJson('/api/messages/'.$item->item_id, [
            'receiver_id' => $student->user_id,
            'message' => 'Yes, still available.',
            'reply_to_message_id' => $asked->message_id,
        ])->assertStatus(201)
            ->assertJsonPath('data.reply_to.message_id', $asked->message_id)
            ->assertJsonPath('data.reply_to.message', 'Available pa po?')
            ->assertJsonPath('data.reply_to.sender_id', $student->user_id);

        $this->assertSame($asked->message_id, (int) Message::find($sent->json('data.message_id'))->reply_to_message_id);

        // Both sides read the quote back with the thread.
        $this->actingAs($student)->getJson('/api/messages/'.$item->item_id.'?other_user_id='.$admin->user_id)
            ->assertOk()
            ->assertJsonPath('data.1.reply_to.message_id', $asked->message_id)
            ->assertJsonPath('data.1.reply_to.message', 'Available pa po?')
            ->assertJsonPath('data.0.reply_to', null);
    }

    #[Test]
    public function a_reply_must_point_at_a_message_in_this_thread(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $stranger = $this->student();
        $item = $this->publishedItem();
        $otherItem = $this->publishedItem();
        $elsewhere = $this->chat($stranger, $admin, $otherItem->item_id, 'A different thread');
        $privateLine = $this->chat($stranger, $admin, $item->item_id, 'Someone else about this item');

        foreach ([$elsewhere, $privateLine] as $foreign) {
            $this->actingAs($student)->postJson('/api/messages/'.$item->item_id, [
                'receiver_id' => $admin->user_id,
                'message' => 'Quoting what I cannot see',
                'reply_to_message_id' => $foreign->message_id,
            ])->assertStatus(422);
        }

        $this->actingAs($student)->postJson('/api/messages/'.$item->item_id, [
            'receiver_id' => $admin->user_id,
            'message' => 'Quoting nothing',
            'reply_to_message_id' => 999999,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_thread_can_be_pinned_archived_and_renamed_for_one_person_only(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $item = $this->publishedItem();
        $this->chat($student, $admin, $item->item_id, 'Hello');

        $this->actingAs($admin)->patchJson('/api/conversations/'.$item->item_id.'/'.$student->user_id, [
            'custom_name' => 'Calc buyer - rush',
            'is_pinned' => true,
        ])->assertOk()
            ->assertJsonPath('data.custom_name', 'Calc buyer - rush')
            ->assertJsonPath('data.is_pinned', true)
            ->assertJsonPath('data.is_archived', false);

        $this->getJson('/api/conversations')->assertOk()
            ->assertJsonPath('data.0.custom_name', 'Calc buyer - rush')
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('data.0.is_archived', false);

        // The student's side is untouched.
        $this->actingAs($student)->getJson('/api/conversations')->assertOk()
            ->assertJsonPath('data.0.custom_name', null)
            ->assertJsonPath('data.0.is_pinned', false);

        // A blank name clears the custom one; archiving unpins.
        $this->actingAs($admin)->patchJson('/api/conversations/'.$item->item_id.'/'.$student->user_id, [
            'custom_name' => '',
            'is_archived' => true,
        ])->assertOk()
            ->assertJsonPath('data.custom_name', null)
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.is_pinned', false);

        // Answering an archived thread brings it back to the inbox.
        $this->postJson('/api/messages/'.$item->item_id, [
            'receiver_id' => $student->user_id, 'message' => 'Back to you',
        ])->assertStatus(201);
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('data.0.is_archived', false);

        // Names have a limit, and a thread that does not exist cannot be renamed.
        $this->patchJson('/api/conversations/'.$item->item_id.'/'.$student->user_id, [
            'custom_name' => str_repeat('x', 81),
        ])->assertStatus(422);
        $this->patchJson('/api/conversations/'.$item->item_id.'/999999', ['is_pinned' => true])->assertStatus(404);
    }

    #[Test]
    public function pinned_threads_come_first_in_the_list(): void
    {
        $admin = $this->admin();
        $first = $this->student();
        $second = $this->student();
        $item = $this->publishedItem();
        $this->chat($first, $admin, $item->item_id, 'older', ['sent_at' => now()->subHour()]);
        $this->chat($second, $admin, $item->item_id, 'newer');

        $this->actingAs($admin)->getJson('/api/conversations')->assertOk()
            ->assertJsonPath('data.0.other_user_id', $second->user_id);

        $this->patchJson('/api/conversations/'.$item->item_id.'/'.$first->user_id, ['is_pinned' => true])->assertOk();

        $this->getJson('/api/conversations')->assertOk()
            ->assertJsonPath('data.0.other_user_id', $first->user_id)
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('data.1.other_user_id', $second->user_id);
    }

    #[Test]
    public function clearing_a_thread_hides_its_history_for_that_person_until_something_new_arrives(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $item = $this->publishedItem();
        $this->chat($student, $admin, $item->item_id, 'Old line one', ['sent_at' => now()->subMinutes(5)]);
        $this->chat($admin, $student, $item->item_id, 'Old line two', ['sent_at' => now()->subMinutes(4)]);

        $this->actingAs($admin)->deleteJson('/api/conversations/'.$item->item_id.'/'.$student->user_id)->assertOk();

        // Gone from the store's list and thread...
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messages/'.$item->item_id.'?other_user_id='.$student->user_id)
            ->assertOk()->assertJsonCount(0, 'data');

        // ...but the student still has everything.
        $this->actingAs($student)->getJson('/api/messages/'.$item->item_id.'?other_user_id='.$admin->user_id)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(1, 'data');

        // A new message brings the thread back, carrying only what came after.
        $this->travel(1)->minutes();
        $this->actingAs($student)->postJson('/api/messages/'.$item->item_id, [
            'receiver_id' => $admin->user_id, 'message' => 'Are you there?',
        ])->assertStatus(201);

        $this->actingAs($admin)->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latest_message', 'Are you there?')
            ->assertJsonPath('data.0.message_count', 1)
            ->assertJsonPath('data.0.unread_count', 1);
        $this->getJson('/api/messages/'.$item->item_id.'?other_user_id='.$student->user_id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Are you there?');

        $this->assertNotNull(ConversationSetting::where('user_id', $admin->user_id)->first()->cleared_at);
    }

    #[Test]
    public function thread_housekeeping_needs_a_session(): void
    {
        $this->patchJson('/api/conversations/1/2', ['is_pinned' => true])->assertUnauthorized();
        $this->deleteJson('/api/conversations/1/2')->assertUnauthorized();
    }
}

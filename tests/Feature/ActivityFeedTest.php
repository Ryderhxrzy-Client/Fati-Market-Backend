<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\StudentInformation;
use App\Models\Transaction;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The store's history, and who each line belongs to.
 *
 * The feed used to carry a display name and nothing else, so every row drew
 * the same anonymous icon. These tests hold it to naming the person: their
 * id, their photo, the other party where there is one, and the facts behind
 * the sentence.
 */
class ActivityFeedTest extends MarketplaceTestCase
{
    private function withPhoto(User $user, string $first, string $last, string $photo): User
    {
        StudentInformation::create([
            'user_id' => $user->user_id,
            'first_name' => $first,
            'last_name' => $last,
            'profile_picture' => $photo,
        ]);

        return $user->fresh();
    }

    #[Test]
    public function a_registration_carries_the_student_and_their_photo(): void
    {
        $admin = $this->admin();
        $student = $this->withPhoto($this->student(), 'Sheryl Cris', 'Carigma', 'https://cdn.test/sheryl.jpg');

        $row = collect($this->actingAs($admin)->getJson('/api/admin/activity?type=user')->assertOk()->json('data'))
            ->firstWhere('resource_id', $student->user_id);

        $this->assertSame('create', $row['action']);
        $this->assertSame('Sheryl Cris Carigma', $row['user']);
        $this->assertSame($student->user_id, $row['user_id']);
        $this->assertSame('https://cdn.test/sheryl.jpg', $row['user_photo']);
        $this->assertSame('student', $row['user_role']);
        $this->assertSame('Registered a student account', $row['description']);

        // The facts behind the line, for the detail view.
        $this->assertContains(
            ['label' => 'Email', 'value' => $student->email],
            $row['details'],
        );
    }

    #[Test]
    public function a_handover_is_the_admin_who_did_it_with_the_buyer_as_its_subject(): void
    {
        $admin = $this->withPhoto($this->admin(), 'Ofelia', 'Store', 'https://cdn.test/ofelia.jpg');
        $buyer = $this->withPhoto($this->student(), 'Sheryl Cris', 'Carigma', 'https://cdn.test/sheryl.jpg');
        $item = $this->publishedItem('250', '180');

        $order = Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $buyer->user_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '250.00',
            'amount_due' => '250.00',
            'payment_method' => 'cash',
            'payment_status' => 'verified',
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $admin->user_id,
            'transaction_date' => now(),
        ]);

        $rows = collect($this->actingAs($admin)->getJson('/api/admin/activity?type=order')->assertOk()->json('data'));

        $handover = $rows->first(fn ($row) => str_starts_with($row['description'], 'Handed'));

        $this->assertNotNull($handover, 'the completed order is missing from the feed');
        $this->assertSame($admin->user_id, $handover['user_id']);
        $this->assertSame('https://cdn.test/ofelia.jpg', $handover['user_photo']);
        $this->assertSame('admin', $handover['user_role']);
        $this->assertStringContainsString('Sheryl Cris Carigma', $handover['description']);

        // The other person in the event keeps their own face.
        $this->assertSame($buyer->user_id, $handover['subject']['user_id']);
        $this->assertSame('https://cdn.test/sheryl.jpg', $handover['subject']['photo']);

        // And the order the row is about, for the detail view.
        $this->assertContains(['label' => 'Buyer', 'value' => 'Sheryl Cris Carigma'], $handover['details']);
        $this->assertSame($order->transaction_id, $handover['resource_id']);
    }

    #[Test]
    public function an_item_line_names_the_seller_and_the_admin_who_received_it(): void
    {
        $admin = $this->withPhoto($this->admin(), 'Ofelia', 'Store', 'https://cdn.test/ofelia.jpg');
        $seller = $this->withPhoto($this->student(), 'Juan', 'Dela Cruz', 'https://cdn.test/juan.jpg');

        $item = Item::factory()->acquired('180')->for($seller, 'seller')->create(['title' => 'Scientific calculator']);
        $item->update(['acquired_by' => $admin->user_id, 'acquired_at' => now()]);

        $rows = collect($this->actingAs($admin)->getJson('/api/admin/activity?type=item')->assertOk()->json('data'));

        $listed = $rows->first(fn ($row) => str_starts_with($row['description'], 'Listed'));
        $received = $rows->first(fn ($row) => str_starts_with($row['description'], 'Received'));

        $this->assertSame($seller->user_id, $listed['user_id']);
        $this->assertSame('https://cdn.test/juan.jpg', $listed['user_photo']);

        $this->assertSame($admin->user_id, $received['user_id']);
        $this->assertSame('https://cdn.test/ofelia.jpg', $received['user_photo']);
        $this->assertSame($seller->user_id, $received['subject']['user_id']);
        $this->assertContains(['label' => 'Item', 'value' => 'Scientific calculator'], $received['details']);
    }

    #[Test]
    public function the_original_fields_are_still_there_for_older_builds(): void
    {
        $admin = $this->admin();
        $this->withPhoto($this->student(), 'Juan', 'Dela Cruz', 'https://cdn.test/juan.jpg');

        $row = $this->actingAs($admin)->getJson('/api/admin/activity')->assertOk()->json('data.0');

        foreach (['action', 'user', 'description', 'resource_type', 'resource_id', 'timestamp'] as $field) {
            $this->assertArrayHasKey($field, $row);
        }
    }

    #[Test]
    public function only_an_admin_may_read_the_feed(): void
    {
        $this->actingAs($this->student())->getJson('/api/admin/activity')->assertStatus(403);

        // One test, two requests: the framework holds on to the user it just
        // resolved, so a signed-out request has to say so explicitly.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/activity')->assertStatus(401);
    }
}

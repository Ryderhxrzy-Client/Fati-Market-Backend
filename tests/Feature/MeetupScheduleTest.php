<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Meet-ups are booked inside store hours and within the current month, and the
 * hours come from .env through config/store.php.
 */
class MeetupScheduleTest extends MarketplaceTestCase
{
    private User $admin;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // Mon-Sat, 8 AM to 5 PM, whatever the local .env says.
        config([
            'store.open_time' => '08:00',
            'store.close_time' => '17:00',
            'store.open_days' => '1,2,3,4,5,6',
            'store.slot_minutes' => 30,
        ]);

        // A Wednesday morning, an hour before opening.
        $this->travelTo(Carbon::parse('2026-09-09 07:00:00'));

        $this->admin = $this->admin();
        $this->item = Item::factory()
            ->for($this->student(), 'seller')
            ->create(['acquisition_price' => '250.00']);
    }

    private function book(?string $at)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/admin/items/{$this->item->item_id}/meetup", ['meetup_schedule' => $at]);
    }

    #[Test]
    public function the_store_hours_are_served_from_config(): void
    {
        $this->getJson('/api/store/hours')
            ->assertOk()
            ->assertJsonPath('data.open_time', '08:00')
            ->assertJsonPath('data.close_time', '17:00')
            ->assertJsonPath('data.open_days', [1, 2, 3, 4, 5, 6])
            ->assertJsonPath('data.slot_minutes', 30)
            ->assertJsonPath('data.hours_label', '8:00 AM - 5:00 PM')
            ->assertJsonPath('data.timezone', config('app.timezone'));
    }

    #[Test]
    public function env_values_are_normalised(): void
    {
        config([
            'store.open_time' => '9:30',
            'store.close_time' => '18:00',
            'store.open_days' => ' 7, 1, 9, x',
        ]);

        $this->getJson('/api/store/hours')
            ->assertOk()
            ->assertJsonPath('data.open_time', '09:30')
            ->assertJsonPath('data.open_days', [1, 7]);
    }

    #[Test]
    public function an_unreadable_env_value_falls_back_rather_than_closing_the_store(): void
    {
        config(['store.close_time' => 'late', 'store.open_days' => 'none']);

        $this->getJson('/api/store/hours')
            ->assertOk()
            ->assertJsonPath('data.open_time', '08:00')
            ->assertJsonPath('data.close_time', '17:00')
            ->assertJsonPath('data.open_days', [1, 2, 3, 4, 5, 6]);
    }

    #[Test]
    public function a_time_inside_store_hours_this_month_is_booked(): void
    {
        $this->book('2026-09-10 10:30:00')->assertOk();

        $this->assertSame(
            '2026-09-10 10:30:00',
            $this->item->fresh()->meetup_schedule->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function a_time_that_has_passed_is_refused(): void
    {
        $this->book('2026-09-08 10:00:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'That time has already passed. Pick a later time.');

        $this->assertNull($this->item->fresh()->meetup_schedule);
    }

    #[Test]
    public function next_month_is_refused(): void
    {
        $this->book('2026-10-01 10:00:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('meetup_schedule')
            ->assertJsonPath('message', 'Meet-ups can only be booked within September 2026.');
    }

    #[Test]
    public function a_day_the_store_is_closed_is_refused(): void
    {
        $this->book('2026-09-13 10:00:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The store is closed on Sundays.');
    }

    #[Test]
    public function times_outside_store_hours_are_refused(): void
    {
        $this->book('2026-09-10 07:30:00')->assertStatus(422);
        $this->book('2026-09-10 17:00:00')->assertStatus(422);

        // The last slot starts before closing.
        $this->book('2026-09-10 16:30:00')->assertOk();
    }

    #[Test]
    public function the_hours_follow_the_env_settings(): void
    {
        config(['store.open_days' => '1,2,3,4,5,6,7', 'store.close_time' => '20:00']);

        $this->book('2026-09-13 19:30:00')->assertOk();
    }

    #[Test]
    public function the_schedule_can_still_be_cleared(): void
    {
        $this->book('2026-09-10 10:30:00')->assertOk();
        $this->book(null)->assertOk();

        $this->assertNull($this->item->fresh()->meetup_schedule);
    }
}

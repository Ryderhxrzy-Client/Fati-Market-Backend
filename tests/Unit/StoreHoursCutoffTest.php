<?php

namespace Tests\Unit;

use App\Support\StoreHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When a buyer checks out, which day is the first they can pick up?
 *
 * Store hours are Monday to Saturday, 8:00 AM to 6:00 PM. Checking out
 * before opening (even at midnight) still lands on the same day; only an
 * order at or after closing, or on a closed day, moves to the next open day.
 */
class StoreHoursCutoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Manila',
            'store.open_time' => '08:00',
            'store.close_time' => '18:00',
            'store.open_days' => '1,2,3,4,5,6',
        ]);
    }

    /** @return array<string, array{string, string, bool}> now, earliest pick-up date, past cutoff */
    public static function moments(): array
    {
        // November 2026: Wed 4, Thu 5, Sat 7, Sun 8, Mon 9.
        return [
            'Wednesday 12:00 AM (midnight)' => ['2026-11-04 00:00:00', '2026-11-04', false],
            'Wednesday 12:24 AM' => ['2026-11-04 00:24:00', '2026-11-04', false],
            'Wednesday 7:00 AM' => ['2026-11-04 07:00:00', '2026-11-04', false],
            'Wednesday 7:59 AM' => ['2026-11-04 07:59:59', '2026-11-04', false],
            'Wednesday 8:00 AM (opening)' => ['2026-11-04 08:00:00', '2026-11-04', false],
            'Wednesday 10:00 AM' => ['2026-11-04 10:00:00', '2026-11-04', false],
            'Wednesday 5:59 PM' => ['2026-11-04 17:59:59', '2026-11-04', false],
            'Wednesday 6:00 PM (closing)' => ['2026-11-04 18:00:00', '2026-11-05', true],
            'Wednesday 6:30 PM' => ['2026-11-04 18:30:00', '2026-11-05', true],
            'Wednesday 11:59 PM' => ['2026-11-04 23:59:59', '2026-11-05', true],
            'Saturday 7:00 PM' => ['2026-11-07 19:00:00', '2026-11-09', true],
            'Sunday 12:30 AM' => ['2026-11-08 00:30:00', '2026-11-09', true],
            'Sunday 10:00 AM' => ['2026-11-08 10:00:00', '2026-11-09', true],
            'Sunday 8:00 PM' => ['2026-11-08 20:00:00', '2026-11-09', true],
        ];
    }

    #[Test]
    #[DataProvider('moments')]
    public function earliest_pickup_date_follows_store_hours(string $now, string $expectedDate, bool $pastCutoff): void
    {
        $at = Carbon::parse($now, 'Asia/Manila');

        $this->assertSame($pastCutoff, StoreHours::isPastCutoff($at), "isPastCutoff at {$now}");
        $this->assertSame($expectedDate, StoreHours::earliestPickupDate($at)->toDateString(), "earliest at {$now}");
    }

    #[Test]
    public function a_moment_given_in_another_timezone_is_read_in_store_time(): void
    {
        // 16:30 UTC on Tuesday is 00:30 Wednesday in Manila: still Wednesday.
        $utc = Carbon::parse('2026-11-03 16:30:00', 'UTC');

        $this->assertFalse(StoreHours::isPastCutoff($utc));
        $this->assertSame('2026-11-04', StoreHours::earliestPickupDate($utc)->toDateString());
    }

    #[Test]
    public function the_schedule_note_names_the_actual_next_open_day(): void
    {
        $saturdayEvening = Carbon::parse('2026-11-07 19:00:00', 'Asia/Manila');
        $this->assertStringContainsString('on Monday, Nov 9', StoreHours::pickupScheduleNote($saturdayEvening));

        $wednesdayEvening = Carbon::parse('2026-11-04 18:30:00', 'Asia/Manila');
        $this->assertStringContainsString('tomorrow', StoreHours::pickupScheduleNote($wednesdayEvening));

        $midnight = Carbon::parse('2026-11-04 00:24:00', 'Asia/Manila');
        $this->assertStringContainsString('available today', StoreHours::pickupScheduleNote($midnight));
    }
}

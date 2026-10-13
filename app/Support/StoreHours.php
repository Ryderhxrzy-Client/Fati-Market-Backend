<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * When the store is open, read from config/store.php.
 *
 * Meet-ups are booked against these hours, and only within the current month:
 * the calendar Ofelia plans with is this month's, and a date that has already
 * gone by is no use to anyone. The app renders its picker from [toArray], so
 * a slot it offers is a slot [refusalFor] accepts.
 *
 * A bad value in .env falls back to the default rather than locking every
 * booking out.
 */
class StoreHours
{
    private const DEFAULT_OPEN = '08:00';
    private const DEFAULT_CLOSE = '17:00';
    private const DEFAULT_DAYS = [1, 2, 3, 4, 5, 6];
    private const DEFAULT_SLOT_MINUTES = 30;

    /** Opening time, "HH:MM". */
    public static function openTime(): string
    {
        return self::window()[0];
    }

    /** Closing time, "HH:MM". The last meet-up must start before it. */
    public static function closeTime(): string
    {
        return self::window()[1];
    }

    /** @return list<int> ISO-8601 weekdays, 1 = Monday ... 7 = Sunday */
    public static function openDays(): array
    {
        $raw = config('store.open_days');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        $days = array_values(array_unique(array_filter(
            array_map(fn ($day) => (int) trim((string) $day), $parts),
            fn (int $day) => $day >= 1 && $day <= 7,
        )));
        sort($days);

        return $days === [] ? self::DEFAULT_DAYS : $days;
    }

    public static function slotMinutes(): int
    {
        $minutes = (int) config('store.slot_minutes');

        return $minutes >= 5 && $minutes <= 240 ? $minutes : self::DEFAULT_SLOT_MINUTES;
    }

    /** "8:00 AM - 5:00 PM" */
    public static function label(): string
    {
        return self::display(self::openTime()) . ' - ' . self::display(self::closeTime());
    }

    /**
     * Why a meet-up cannot be booked at this moment, or null when it can.
     *
     * Worded for the admin who picked the time, since the message is shown to
     * them as-is.
     */
    public static function refusalFor(Carbon $at, ?Carbon $now = null): ?string
    {
        $timezone = config('app.timezone');
        $at = $at->copy()->setTimezone($timezone);
        $now = ($now ?? Carbon::now($timezone))->copy()->setTimezone($timezone);

        if ($at->lessThanOrEqualTo($now)) {
            return 'That time has already passed. Pick a later time.';
        }

        if (!$at->isSameMonth($now)) {
            return 'Meet-ups can only be booked within ' . $now->format('F Y') . '.';
        }

        if (!in_array($at->dayOfWeekIso, self::openDays(), true)) {
            return 'The store is closed on ' . $at->format('l') . 's.';
        }

        $time = $at->format('H:i');

        if ($time < self::openTime() || $time >= self::closeTime()) {
            return 'Pick a time within store hours, ' . self::label() . '.';
        }

        $closing = $at->copy()->setTimeFromTimeString(self::closeTime());
        if ($at->copy()->addMinutes(self::slotMinutes())->greaterThan($closing)) {
            return 'The entire '.self::slotMinutes().'-minute meet-up must fit within store hours, '.self::label().'.';
        }

        return null;
    }

    /** Cutoff time string, e.g. "17:00" (5:00 PM) */
    public static function cutoffTime(): string
    {
        return self::closeTime();
    }

    /** Display label for cutoff time, e.g. "5:00 PM" */
    public static function cutoffLabel(): string
    {
        return self::display(self::cutoffTime());
    }

    /**
     * Whether the current moment (or a given time) is past the store's transaction cutoff.
     * At or after 5:00 PM (17:00), or on non-operational days/hours.
     */
    public static function isPastCutoff(?Carbon $now = null): bool
    {
        $timezone = config('app.timezone');
        $now = ($now ?? Carbon::now($timezone))->copy()->setTimezone($timezone);
        $time = $now->format('H:i');
        $dayOfWeek = $now->dayOfWeekIso;

        if (!in_array($dayOfWeek, self::openDays(), true)) {
            return true;
        }

        return $time >= self::cutoffTime() || $time < self::openTime();
    }

    /**
     * Automated pickup/meetup timeframe note based on cutoff.
     */
    public static function pickupScheduleNote(?Carbon $now = null): string
    {
        $timezone = config('app.timezone');
        $now = ($now ?? Carbon::now($timezone))->copy()->setTimezone($timezone);
        $cutoff = self::cutoffLabel();

        if (self::isPastCutoff($now)) {
            return "Order placed after the {$cutoff} cutoff. Meet-up and store pickup scheduled for tomorrow at Ofelia's store during store hours (" . self::label() . ').';
        }

        return "Order placed within store hours. Meet-up and store pickup available today before {$cutoff} at Ofelia's store.";
    }

    /** What the app needs to draw the calendar, time slots and cutoff info. */
    public static function toArray(): array
    {
        return [
            'open_time' => self::openTime(),
            'close_time' => self::closeTime(),
            'open_days' => self::openDays(),
            'slot_minutes' => self::slotMinutes(),
            'hours_label' => self::label(),
            'cutoff_time' => self::cutoffTime(),
            'cutoff_label' => self::cutoffLabel(),
            'is_past_cutoff' => self::isPastCutoff(),
            'pickup_schedule_note' => self::pickupScheduleNote(),
            'timezone' => config('app.timezone'),
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function window(): array
    {
        $open = self::time(config('store.open_time'));
        $close = self::time(config('store.close_time'));

        // A store that closes before it opens is a typo, not a schedule.
        if ($open === null || $close === null || $close <= $open) {
            return [self::DEFAULT_OPEN, self::DEFAULT_CLOSE];
        }

        return [$open, $close];
    }

    /** Normalise "8:00" to "08:00"; null when it is not a time of day. */
    private static function time(mixed $raw): ?string
    {
        if (!is_string($raw) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($raw), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    private static function display(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('g:i A');
    }
}

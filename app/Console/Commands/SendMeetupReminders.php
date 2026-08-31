<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Remind a seller their meet-up is coming.
 *
 * An accepted offer gets a schedule; a student forgets a schedule. Three
 * pushes go out as the time approaches - about six hours, one hour and thirty
 * minutes before - each sent at most once, tracked on the item itself so a
 * rescheduled meet-up starts the reminders over.
 */
class SendMeetupReminders extends Command
{
    protected $signature = 'meetups:remind';

    protected $description = 'Push the 6h / 1h / 30m reminders for upcoming item turnover meet-ups';

    /** Minutes-before thresholds, largest first, with the wording for each. */
    private const REMINDERS = [
        360 => 'in about 6 hours',
        60 => 'in about 1 hour',
        30 => 'in 30 minutes',
    ];

    public function handle(FcmService $fcm): int
    {
        // Only offers still waiting to be brought in have a meet-up to keep:
        // accepted (priced) but not yet acquired.
        $items = Item::whereNotNull('meetup_schedule')
            ->whereNotNull('acquisition_price')
            ->whereIn('status', [Item::STATUS_PENDING, Item::STATUS_LEGACY_PRIVATE])
            ->where('meetup_schedule', '>', now())
            ->get();

        $sent = 0;

        foreach ($items as $item) {
            $minutesLeft = now()->diffInMinutes(Carbon::parse($item->meetup_schedule), false);

            if ($minutesLeft <= 0) {
                continue;
            }

            $already = array_filter(explode(',', (string) $item->meetup_reminders_sent));

            foreach (self::REMINDERS as $threshold => $phrase) {
                if ($minutesLeft > $threshold || in_array((string) $threshold, $already, true)) {
                    continue;
                }

                $seller = User::where('user_id', $item->seller_id)->first();

                if ($seller !== null) {
                    $fcm->sendItemNotification(
                        $item,
                        $seller,
                        'meetup_reminder',
                        'Meet-up reminder',
                        "Your turnover for \"{$item->title}\" is {$phrase} - "
                            . Carbon::parse($item->meetup_schedule)->format('M j, g:i A')
                            . '. Bring the item and your QR code.',
                    );
                }

                // Marked even when the seller has no registered device: the
                // moment has passed either way, and re-sending a stale "6
                // hours before" push later would only confuse.
                $already[] = (string) $threshold;
                $sent++;

                // One reminder per run per item - the nearest overdue
                // threshold - so a schedule set 20 minutes out gets a single
                // "30 minutes" push, not all three at once.
                break;
            }

            $item->update(['meetup_reminders_sent' => implode(',', $already)]);
        }

        $this->info("Sent {$sent} meet-up reminder(s).");

        return self::SUCCESS;
    }
}

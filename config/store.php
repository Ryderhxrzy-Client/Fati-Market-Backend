<?php

/*
 * Ofelia's store hours.
 *
 * A meet-up is a seller bringing their item in, so it can only be booked while
 * the store is open. The app draws its calendar and time slots from these same
 * figures (GET /api/store/hours), so set them in .env: a change of schedule
 * then needs no code change and no app release.
 */
return [
    // 24-hour "HH:MM", in the app timezone (config/app.php).
    'open_time' => env('STORE_OPEN_TIME', '08:00'),
    'close_time' => env('STORE_CLOSE_TIME', '17:00'),

    // The weekdays the store opens, ISO-8601: 1 = Monday ... 7 = Sunday.
    'open_days' => env('STORE_OPEN_DAYS', '1,2,3,4,5,6'),

    // How long one bookable meet-up slot is, in minutes.
    'slot_minutes' => (int) env('STORE_SLOT_MINUTES', 30),
];

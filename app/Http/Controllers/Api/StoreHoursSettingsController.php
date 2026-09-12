<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreHoursSetting;
use App\Support\StoreHours;
use Illuminate\Http\Request;

class StoreHoursSettingsController extends Controller
{
    public function show()
    {
        return response()->json([
            'message' => 'Store hours retrieved successfully',
            'data' => StoreHours::toArray(),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i'],
            'open_days' => ['required', 'array', 'min:1'],
            'open_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'slot_minutes' => ['required', 'integer', 'between:5,240'],
        ]);

        if ($data['close_time'] <= $data['open_time']) {
            return response()->json([
                'message' => 'Closing time must be after opening time.',
                'errors' => ['close_time' => ['Closing time must be after opening time.']],
            ], 422);
        }

        $days = array_values(array_unique(array_map('intval', $data['open_days'])));
        sort($days);

        $values = [
            'open_time' => $data['open_time'],
            'close_time' => $data['close_time'],
            'open_days' => implode(',', $days),
            'slot_minutes' => $data['slot_minutes'],
        ];
        $current = StoreHoursSetting::query()->find(1);
        if ($current !== null &&
            $current->open_time === $values['open_time'] &&
            $current->close_time === $values['close_time'] &&
            $current->open_days === $values['open_days'] &&
            (int) $current->slot_minutes === (int) $values['slot_minutes']) {
            return response()->json([
                'message' => 'No changes detected. These store hours are already saved.',
                'errors' => ['store_hours' => ['No changes detected. These values are already saved.']],
            ], 422);
        }

        StoreHoursSetting::updateOrCreate(['id' => 1], $values + [
            'updated_by' => $request->user()->user_id,
        ]);

        return response()->json([
            'message' => 'Store hours saved.',
            'data' => StoreHours::toArray(),
        ])->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use App\Services\PhotoUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentSettingsController extends Controller
{
    public function show()
    {
        return response()->json(['data' => PaymentSetting::gcashDetails()])
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, PhotoUploader $photos)
    {
        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'regex:/^(09[0-9]{9}|\\+639[0-9]{9})$/'],
            'qr_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'remove_qr' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('remove_qr') && $request->hasFile('qr_image')) {
            return response()->json(['message' => 'Choose a new QR or remove the current QR, not both.'], 422);
        }

        $qr = null;
        if ($request->hasFile('qr_image')) {
            if ($error = $photos->validateImages([$request->file('qr_image')])) {
                return response()->json($error, 422);
            }
            $qr = $photos->upload($request->file('qr_image'), 'payment-settings/gcash');
            if ($qr === null) {
                return response()->json(['message' => 'QR upload failed. Your payment settings were not changed.'], 503);
            }
        }

        DB::transaction(function () use ($request, $data, $qr) {
            $settings = PaymentSetting::firstOrCreate(['method' => 'gcash'], [
                'account_name' => $data['account_name'],
                'account_number' => $data['account_number'],
                'qr_image_url' => config('services.gcash.qr_image_url'),
            ]);
            $values = [
                'account_name' => trim($data['account_name']),
                'account_number' => str_starts_with($data['account_number'], '+63')
                    ? '0'.substr($data['account_number'], 3) : $data['account_number'],
                'updated_by' => $request->user()->user_id,
            ];
            if ($qr !== null || $request->boolean('remove_qr')) {
                $values['qr_image_url'] = $qr;
            }
            $settings->update($values);
        });

        return response()->json([
            'message' => 'GCash payment settings saved.',
            'data' => PaymentSetting::gcashDetails(),
        ])->header('Cache-Control', 'no-store');
    }
}

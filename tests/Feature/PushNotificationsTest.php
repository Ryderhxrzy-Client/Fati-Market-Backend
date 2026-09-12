<?php

namespace Tests\Feature;

use App\Models\FcmDeviceToken;
use App\Services\FcmService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class PushNotificationsTest extends MarketplaceTestCase
{
    public function test_two_devices_survive_rotation_and_one_device_logout(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);
        $first = '8fb1a00d-46fb-4b44-8f23-81797d19f67a';
        $second = 'ed7b88c0-bf7a-4f92-a172-005c6b539371';
        foreach ([['one', $first], ['two', $second], ['rotated', $first]] as [$token, $device]) {
            $this->postJson('/api/device-tokens', ['token' => $token, 'device_id' => $device])->assertOk();
        }
        $this->assertEqualsCanonicalizing(['two', 'rotated'], FcmDeviceToken::pluck('token')->all());
        $this->deleteJson('/api/device-tokens', ['token' => 'rotated'])->assertOk();
        $this->assertSame(['two'], FcmDeviceToken::pluck('token')->all());
    }

    public function test_legacy_same_model_devices_remain_registered_and_account_switch_transfers_only_one_token(): void
    {
        $first = $this->student();
        $second = $this->student();
        Sanctum::actingAs($first);
        foreach (['one', 'two'] as $token) {
            $this->postJson('/api/device-tokens', ['token' => $token, 'device_id' => 'Same model'])->assertOk();
        }
        Sanctum::actingAs($second);
        $this->postJson('/api/device-tokens', ['token' => 'one'])->assertOk();
        Sanctum::actingAs($first);
        $this->deleteJson('/api/device-tokens', ['token' => 'one'])->assertOk();
        $this->assertSame($second->user_id, FcmDeviceToken::where('token', 'one')->first()->user_id);
        $this->assertSame($first->user_id, FcmDeviceToken::where('token', 'two')->first()->user_id);
    }

    public function test_all_devices_are_attempted_and_only_unregistered_tokens_are_removed(): void
    {
        $recipient = $this->student();
        $item = $this->publishedItem(seller: $recipient);
        foreach (['bad-payload', 'missing-project', 'expired', 'healthy'] as $token) {
            FcmDeviceToken::create([
                'user_id' => $recipient->user_id, 'token' => $token, 'token_hash' => hash('sha256', $token),
            ]);
        }
        config(['services.fcm.project_id' => 'test-project']);
        Http::preventStrayRequests();
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['status' => 'INVALID_ARGUMENT']], 400)
            ->push(['error' => ['status' => 'NOT_FOUND']], 404)
            ->push(['error' => ['details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                'errorCode' => 'UNREGISTERED',
            ]]]], 404)
            ->push(['name' => 'delivered'], 200),
        ]);
        $service = new class extends FcmService
        {
            protected function accessToken(): string
            {
                return 'fake-access-token';
            }
        };
        $service->sendItemNotification($item, $recipient, 'item_update', 'Updated', 'Your offer was accepted.');
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request['message']['token'] === 'healthy'
            && $request['message']['data']['recipient_id'] === (string) $recipient->user_id
            && $request['message']['android']['priority'] === 'HIGH'
        );
        $this->assertEqualsCanonicalizing(
            ['bad-payload', 'missing-project', 'healthy'], FcmDeviceToken::pluck('token')->all(),
        );
    }
}

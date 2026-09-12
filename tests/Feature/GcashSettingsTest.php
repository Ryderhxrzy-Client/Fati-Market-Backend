<?php

namespace Tests\Feature;

use App\Models\PaymentSetting;
use App\Services\PhotoUploader;
use Illuminate\Http\UploadedFile;

class GcashSettingsTest extends MarketplaceTestCase
{
    private function values(): array
    {
        return ['account_name' => 'Store Owner', 'account_number' => '09171234567'];
    }

    public function test_only_admin_can_manage_payment_settings(): void
    {
        $this->getJson('/api/admin/settings/gcash')->assertUnauthorized();
        $this->postJson('/api/admin/settings/gcash', $this->values())->assertUnauthorized();
        $this->actingAs($this->student())->getJson('/api/admin/settings/gcash')->assertForbidden();
        $this->postJson('/api/admin/settings/gcash', $this->values())->assertForbidden();
        $this->assertDatabaseCount('payment_settings', 0);
    }

    public function test_checkout_uses_env_until_admin_saves_then_uses_saved_values(): void
    {
        config(['services.gcash.account_name' => 'Original', 'services.gcash.account_number' => '09991234567', 'services.gcash.qr_image_url' => 'https://example.test/old.png']);
        $this->actingAs($this->student())->getJson('/api/checkout/payment-details')
            ->assertOk()->assertJsonPath('data.gcash.account_name', 'Original');

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/settings/gcash', [
            'account_name' => 'New Owner', 'account_number' => '+639171234567',
            'qr_image' => UploadedFile::fake()->image('qr.png'),
        ])->assertOk()->assertJsonPath('data.account_number', '09171234567');
        $qr = PaymentSetting::findOrFail('gcash')->qr_image_url;
        $this->assertStringStartsWith('https://fake-cdn.test/payment-settings/gcash/', $qr);
        $this->assertSame($admin->user_id, PaymentSetting::find('gcash')->updated_by);
        config(['services.gcash.account_name' => 'Changed Env']);
        $this->actingAs($this->student())->getJson('/api/checkout/payment-details')
            ->assertOk()->assertJsonPath('data.gcash.account_name', 'New Owner')
            ->assertJsonPath('data.gcash.account_number', '09171234567')
            ->assertJsonPath('data.gcash.qr_image_url', $qr);
    }

    public function test_qr_is_kept_until_explicitly_replaced_or_removed(): void
    {
        config(['services.gcash.qr_image_url' => 'https://example.test/old.png']);
        $this->actingAs($this->admin())->postJson('/api/admin/settings/gcash', $this->values())->assertOk()
            ->assertJsonPath('data.qr_image_url', 'https://example.test/old.png');
        $this->postJson('/api/admin/settings/gcash', $this->values() + ['remove_qr' => true])
            ->assertOk()->assertJsonPath('data.qr_image_url', null);
        $this->getJson('/api/checkout/payment-details')->assertOk()->assertJsonPath('data.gcash.qr_image_url', null);
        $this->postJson('/api/admin/settings/gcash', $this->values() + ['qr_image' => UploadedFile::fake()->image('new.png')])->assertOk();
        $this->assertNotNull(PaymentSetting::find('gcash')->qr_image_url);
    }

    public function test_invalid_input_leaves_saved_settings_unchanged(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/settings/gcash', $this->values())->assertOk();
        foreach ([
            ['account_name' => ''],
            ['account_number' => '123'],
            ['qr_image' => UploadedFile::fake()->create('qr.svg', 1, 'image/svg+xml')],
            ['qr_image' => UploadedFile::fake()->image('qr.png')->size(5121)],
            ['qr_image' => UploadedFile::fake()->image('qr.png'), 'remove_qr' => true],
        ] as $invalid) {
            $this->postJson('/api/admin/settings/gcash', array_merge($this->values(), $invalid))->assertStatus(422);
        }
        $this->assertSame('Store Owner', PaymentSetting::find('gcash')->account_name);
        $this->assertSame('09171234567', PaymentSetting::find('gcash')->account_number);
    }

    public function test_failed_upload_does_not_save_a_different_payment_account(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/settings/gcash', $this->values())->assertOk();
        $this->app->instance(PhotoUploader::class, new class extends PhotoUploader
        {
            public function upload(UploadedFile $file, string $folder): ?string
            {
                return null;
            }
        });
        $this->postJson('/api/admin/settings/gcash', [
            'account_name' => 'Different Owner', 'account_number' => '09991234567',
            'qr_image' => UploadedFile::fake()->image('qr.png'),
        ])->assertStatus(503);
        $this->assertSame('Store Owner', PaymentSetting::find('gcash')->account_name);
        $this->assertSame('09171234567', PaymentSetting::find('gcash')->account_number);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $primaryKey = 'method';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['method', 'account_name', 'account_number', 'qr_image_url', 'updated_by'];

    public static function gcashDetails(): array
    {
        $settings = static::find('gcash');

        return [
            'account_name' => $settings ? $settings->account_name : config('services.gcash.account_name'),
            'account_number' => $settings ? $settings->account_number : config('services.gcash.account_number'),
            // A saved null means the admin removed the QR; do not restore the env QR.
            'qr_image_url' => $settings ? $settings->qr_image_url : config('services.gcash.qr_image_url'),
        ];
    }
}

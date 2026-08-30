<?php

namespace App\Services;

use App\Models\Message;
use App\Models\FcmDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    public function sendChatMessage(Message $message): void
    {
        $tokens = FcmDeviceToken::where('user_id', $message->receiver_id)->pluck('token')->all();
        if (!$tokens) return;

        $message->loadMissing(['sender.studentInfo', 'receiver.studentInfo', 'item']);
        $sender = $message->sender;
        $info = $sender?->studentInfo;
        $name = trim(($info?->first_name ?? '') . ' ' . ($info?->last_name ?? '')) ?: ($sender?->email ?? 'New message');
        $data = [
            'type' => 'chat_message',
            'message_id' => (string) $message->message_id,
            'item_id' => (string) $message->item_id,
            'item_title' => (string) ($message->item?->title ?? ''),
            'sender_id' => (string) $message->sender_id,
            'sender_name' => $name,
            'sender_profile_picture' => (string) ($info?->profile_picture ?? ''),
            'message' => (string) $message->message,
        ];

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($this->accessToken())
                    ->post('https://fcm.googleapis.com/v1/projects/' . config('services.fcm.project_id') . '/messages:send', [
                        'message' => [
                            'token' => $token,
                            'data' => $data,
                            'android' => ['priority' => 'HIGH'],
                        ],
                    ]);
                if ($response->status() === 404 || $response->status() === 400) {
                    FcmDeviceToken::where('token', $token)->delete();
                }
                if (!$response->successful()) Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->body()]);
            } catch (\Throwable $e) {
                Log::error('FCM exception', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Push an order event.
     *
     * Carries the transaction and item ids so tapping the notification can
     * open the order straight away instead of dumping the user on a list.
     */
    public function sendOrderNotification(
        \App\Models\Transaction $transaction,
        \App\Models\User $recipient,
        string $type,
        string $title,
        string $body,
    ): void {
        $tokens = FcmDeviceToken::where('user_id', $recipient->user_id)->pluck('token')->all();

        if (!$tokens) {
            return;
        }

        $transaction->loadMissing(['item', 'buyer.studentInfo']);
        $buyerInfo = $transaction->buyer?->studentInfo;
        $buyerName = trim(($buyerInfo?->first_name ?? '') . ' ' . ($buyerInfo?->last_name ?? ''))
            ?: ($transaction->buyer?->email ?? 'A buyer');

        $data = [
            'type' => $type,
            'transaction_id' => (string) $transaction->transaction_id,
            'item_id' => (string) $transaction->item_id,
            'item_title' => (string) ($transaction->item?->title ?? ''),
            'buyer_id' => (string) $transaction->buyer_id,
            'buyer_name' => $buyerName,
            'amount_due' => (string) $transaction->amount_due,
            'status' => (string) $transaction->status,
            'title' => $title,
            'body' => $body,
        ];

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($this->accessToken())
                    ->post('https://fcm.googleapis.com/v1/projects/' . config('services.fcm.project_id') . '/messages:send', [
                        'message' => [
                            'token' => $token,
                            'data' => $data,
                            'android' => ['priority' => 'HIGH'],
                        ],
                    ]);

                if ($response->status() === 404 || $response->status() === 400) {
                    FcmDeviceToken::where('token', $token)->delete();
                }

                if (!$response->successful()) {
                    Log::warning('FCM order send failed', ['status' => $response->status()]);
                }
            } catch (\Throwable $e) {
                Log::error('FCM order exception', ['error' => $e->getMessage()]);
            }
        }
    }

    private function accessToken(): string
    {
        $credentialsPath = config('services.fcm.credentials');
        // Allow a portable Laravel-relative path such as storage/fati-market-credentials.json.
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $credentialsPath)) {
            $credentialsPath = base_path($credentialsPath);
        }
        $json = json_decode(file_get_contents($credentialsPath), true, 512, JSON_THROW_ON_ERROR);
        $now = time();
        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->encode([
            'iss' => $json['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]);
        openssl_sign($header . '.' . $claims, $signature, $json['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $header . '.' . $claims . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
        ])->throw();
        return $response->json('access_token');
    }

    private function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}

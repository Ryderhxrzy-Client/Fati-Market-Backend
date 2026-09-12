<?php

namespace App\Services;

use App\Models\FcmDeviceToken;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    public function sendChatMessage(Message $message): void
    {
        $tokens = FcmDeviceToken::where('user_id', $message->receiver_id)->pluck('token')->all();
        if (! $tokens) {
            return;
        }

        $message->loadMissing(['sender.studentInfo', 'receiver.studentInfo', 'item']);
        $sender = $message->sender;
        $info = $sender?->studentInfo;
        $name = trim(($info?->first_name ?? '').' '.($info?->last_name ?? '')) ?: ($sender?->email ?? 'New message');
        $data = [
            'type' => 'chat_message',
            'message_id' => (string) $message->message_id,
            'item_id' => (string) $message->item_id,
            'item_title' => (string) ($message->item?->title ?? ''),
            'sender_id' => (string) $message->sender_id,
            'recipient_id' => (string) $message->receiver_id,
            'sender_name' => $name,
            'sender_profile_picture' => (string) ($info?->profile_picture ?? ''),
            'message' => (string) $message->message,
        ];

        $this->sendToDevices($tokens, $data);
    }

    /**
     * Push an order event.
     *
     * Carries the transaction and item ids so tapping the notification can
     * open the order straight away instead of dumping the user on a list.
     */
    /**
     * Push an item event to one user - an offer decision, a schedule, a
     * meet-up reminder. Same delivery as the order pushes; the type tells the
     * app which it is.
     */
    public function sendItemNotification(
        \App\Models\Item $item,
        \App\Models\User $recipient,
        string $type,
        string $title,
        string $body,
    ): void {
        $tokens = FcmDeviceToken::where('user_id', $recipient->user_id)->pluck('token')->all();

        if (! $tokens) {
            return;
        }

        $data = [
            'type' => $type,
            'recipient_id' => (string) $recipient->user_id,
            'item_id' => (string) $item->item_id,
            'item_title' => (string) $item->title,
            'status' => (string) $item->status,
            'title' => $title,
            'body' => $body,
        ];

        $this->sendToDevices($tokens, $data);
    }

    public function sendOrderNotification(
        \App\Models\Transaction $transaction,
        \App\Models\User $recipient,
        string $type,
        string $title,
        string $body,
    ): void {
        $tokens = FcmDeviceToken::where('user_id', $recipient->user_id)->pluck('token')->all();

        if (! $tokens) {
            return;
        }

        $transaction->loadMissing(['item', 'buyer.studentInfo']);
        $buyerInfo = $transaction->buyer?->studentInfo;
        $buyerName = trim(($buyerInfo?->first_name ?? '').' '.($buyerInfo?->last_name ?? ''))
            ?: ($transaction->buyer?->email ?? 'A buyer');

        $data = [
            'type' => $type,
            'recipient_id' => (string) $recipient->user_id,
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

        $this->sendToDevices($tokens, $data);
    }

    private function sendToDevices(array $tokens, array $data): void
    {
        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::error('FCM authentication failed', ['error' => $e->getMessage()]);

            return;
        }

        foreach (array_unique($tokens) as $token) {
            try {
                $response = Http::withToken($accessToken)->connectTimeout(5)->timeout(15)
                    ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                        'message' => [
                            'token' => $token,
                            'data' => $data,
                            'android' => ['priority' => 'HIGH'],
                        ],
                    ]);

                // A malformed payload or missing project must not erase valid devices.
                $errorCode = collect($response->json('error.details', []))
                    ->firstWhere('@type', 'type.googleapis.com/google.firebase.fcm.v1.FcmError')['errorCode'] ?? null;
                if ($errorCode === 'UNREGISTERED') {
                    FcmDeviceToken::where('token_hash', hash('sha256', $token))->delete();
                }
                if (! $response->successful()) {
                    Log::warning('FCM delivery failed', [
                        'status' => $response->status(),
                        'fcm_error' => $errorCode,
                        'api_status' => $response->json('error.status'),
                        'type' => $data['type'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('FCM delivery exception', ['error' => $e->getMessage()]);
            }
        }
    }

    protected function accessToken(): string
    {
        $credentialsPath = app(FcmCredentials::class)->path();
        // Allow a portable Laravel-relative path such as storage/fati-market-credentials.json.
        if (! preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $credentialsPath)) {
            $credentialsPath = base_path($credentialsPath);
        }
        $json = json_decode(file_get_contents($credentialsPath), true, 512, JSON_THROW_ON_ERROR);
        $now = time();
        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->encode([
            'iss' => $json['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]);
        openssl_sign($header.'.'.$claims, $signature, $json['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $header.'.'.$claims.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
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

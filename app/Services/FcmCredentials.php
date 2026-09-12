<?php

namespace App\Services;

use RuntimeException;

/** Load credentials in memory; a container never needs to write its private key. */
class FcmCredentials
{
    public function data(): array
    {
        $raw = trim((string) config('services.fcm.credentials_json'));
        $encoded = trim((string) config('services.fcm.credentials_base64'));
        if ($raw !== '') {
            $json = $raw;
        } elseif ($encoded !== '') {
            $json = base64_decode($encoded, true);
            if ($json === false) {
                throw new RuntimeException('FCM_SERVICE_ACCOUNT_JSON_BASE64 must be valid base64.');
            }
        } else {
            $path = $this->path();
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Firebase credentials are missing. Set FCM_SERVICE_ACCOUNT_JSON, FCM_SERVICE_ACCOUNT_JSON_BASE64, or a readable FCM_CREDENTIALS file.');
            }
            $json = file_get_contents($path);
        }

        try {
            $credentials = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Firebase credentials must contain valid service-account JSON.');
        }
        if (! is_array($credentials) || ($credentials['type'] ?? '') !== 'service_account') {
            throw new RuntimeException('Firebase credential must be a service-account JSON key.');
        }
        foreach (['project_id', 'private_key', 'client_email'] as $field) {
            if (! isset($credentials[$field]) || ! is_string($credentials[$field]) || trim($credentials[$field]) === '') {
                throw new RuntimeException("Firebase service-account JSON is missing {$field}.");
            }
        }
        if (openssl_pkey_get_private($credentials['private_key']) === false) {
            throw new RuntimeException('Firebase private_key is not a valid PEM private key. Preserve the JSON newline escapes.');
        }

        return $credentials;
    }

    public function projectId(): string
    {
        return trim((string) config('services.fcm.project_id')) ?: $this->data()['project_id'];
    }

    public function path(): string
    {
        $configured = (string) config('services.fcm.credentials', 'storage/fati-market-credentials.json');
        $absolute = str_starts_with($configured, '/') || str_starts_with($configured, '\\')
            || (strlen($configured) > 2 && ctype_alpha($configured[0]) && $configured[1] === ':');

        return $absolute ? $configured : base_path($configured);
    }
}

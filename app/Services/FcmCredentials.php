<?php

namespace App\Services;

use RuntimeException;

/** Recreates the ignored Firebase service-account file from a runtime secret. */
class FcmCredentials
{
    public function path(): string
    {
        $configured = (string) config('services.fcm.credentials', 'storage/fati-market-credentials.json');
        $isAbsolute = str_starts_with($configured, '/')
            || str_starts_with($configured, '\\')
            || (strlen($configured) > 2 && ctype_alpha($configured[0]) && $configured[1] === ':');
        $path = $isAbsolute ? $configured : base_path($configured);
        $encoded = trim((string) config('services.fcm.credentials_base64'));

        // Local development may still use a manually placed, ignored file.
        if ($encoded === '') {
            return $path;
        }

        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new RuntimeException('FCM_SERVICE_ACCOUNT_JSON_BASE64 must be valid base64.');
        }

        try {
            $credentials = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('FCM_SERVICE_ACCOUNT_JSON_BASE64 must decode to service-account JSON.', 0, $e);
        }

        foreach (['type', 'project_id', 'private_key', 'client_email'] as $key) {
            if (empty($credentials[$key])) {
                throw new RuntimeException("Firebase service-account JSON is missing {$key}.");
            }
        }
        if ($credentials['type'] !== 'service_account') {
            throw new RuntimeException('Firebase credential must be a service-account JSON key.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the Firebase credentials directory.');
        }

        // A fresh container has no storage file. Write atomically so a push
        // notification can never read a partially written private key.
        if (!is_file($path) || file_get_contents($path) !== $json) {
            $temporary = $path . '.tmp';
            if (file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write Firebase credentials.');
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to activate Firebase credentials.');
            }
            @chmod($path, 0600);
        }

        return $path;
    }
}

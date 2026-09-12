<?php

namespace Tests\Feature;

use App\Services\FcmCredentials;
use Tests\TestCase;

class FcmCredentialsTest extends TestCase
{
    private function credentials(): array
    {
        // Windows PHP may have no default openssl.cnf. Supply a test-only config.
        $config = tempnam(sys_get_temp_dir(), 'fcm-openssl-');
        try {
            file_put_contents($config, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            $options = ['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = openssl_pkey_new($options);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $pem, null, $options));
        } finally {
            unlink($config);
        }

        return ['type' => 'service_account', 'project_id' => 'test-project', 'client_email' => 'test@example.test', 'private_key' => $pem];
    }

    public function test_raw_json_and_base64_work_without_writing_a_file(): void
    {
        $credentials = $this->credentials();
        config(['services.fcm.credentials' => '/not-writable/credentials.json',
            'services.fcm.credentials_json' => json_encode($credentials),
            'services.fcm.credentials_base64' => 'invalid-base64',
            'services.fcm.project_id' => '',
        ]);
        $this->assertSame($credentials, app(FcmCredentials::class)->data());
        $this->assertSame('test-project', app(FcmCredentials::class)->projectId());
        config(['services.fcm.credentials_json' => '', 'services.fcm.credentials_base64' => base64_encode(json_encode($credentials))]);
        $this->assertSame($credentials, app(FcmCredentials::class)->data());
    }

    public function test_invalid_base64_fails_with_a_safe_message(): void
    {
        config(['services.fcm.credentials_json' => '', 'services.fcm.credentials_base64' => '!!!']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be valid base64');
        app(FcmCredentials::class)->data();
    }

    public function test_invalid_private_key_is_rejected(): void
    {
        $credentials = $this->credentials();
        $credentials['private_key'] = 'invalid-private-key';
        config(['services.fcm.credentials_json' => json_encode($credentials)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid PEM');
        app(FcmCredentials::class)->data();
    }

    public function test_mounted_json_file_is_supported(): void
    {
        $credentials = $this->credentials();
        $path = tempnam(sys_get_temp_dir(), 'fcm-test-');
        try {
            file_put_contents($path, json_encode($credentials));
            config(['services.fcm.credentials_json' => '', 'services.fcm.credentials_base64' => '', 'services.fcm.credentials' => $path]);
            $this->assertSame($credentials, app(FcmCredentials::class)->data());
        } finally {
            unlink($path);
        }
    }
}

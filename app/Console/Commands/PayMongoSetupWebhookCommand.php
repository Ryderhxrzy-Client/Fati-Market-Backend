<?php

namespace App\Console\Commands;

use App\Services\PayMongoService;
use Illuminate\Console\Command;
use Throwable;

class PayMongoSetupWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paymongo:setup-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register or verify PayMongo webhook configuration for test mode';

    /**
     * Execute the console command.
     */
    public function handle(PayMongoService $payMongoService): int
    {
        $this->info('Initializing PayMongo Webhook Setup...');

        try {
            $webhookLink = $payMongoService->getWebhookLink();
            $this->info("Target Webhook URL: {$webhookLink}");

            $result = $payMongoService->setupWebhook();

            if ($result['status'] === 'exists') {
                $this->info('✓ Webhook registration check completed.');
                $this->line("Status: {$result['message']}");
                $this->line("Webhook ID: {$result['webhook_id']}");
                $this->line("Webhook URL: {$result['url']}");
                
                $currentSecret = $payMongoService->getWebhookSecret();
                if (empty($currentSecret)) {
                    $this->warn('! WARNING: Webhook exists on PayMongo, but PAYMONGO_WEBHOOK_SECRET_KEY_TEST is not set in your .env file.');
                } else {
                    $this->info('✓ PAYMONGO_WEBHOOK_SECRET_KEY_TEST is set in .env.');
                }

                return self::SUCCESS;
            }

            $this->info('✓ Webhook created successfully!');
            $this->line("Webhook ID: {$result['webhook_id']}");
            $this->line("Webhook URL: {$result['url']}");

            $secretKey = $result['secret_key'];
            if (!empty($secretKey)) {
                $this->newLine();
                $this->warn('===================================================================');
                $this->warn('IMPORTANT: PAYMONGO WEBHOOK SECRET KEY GENERATED');
                $this->warn('===================================================================');
                $this->line("Secret Key: {$secretKey}");
                $this->newLine();
                $this->info('Copy the key above and update your .env file:');
                $this->comment("PAYMONGO_WEBHOOK_SECRET_KEY_TEST={$secretKey}");

                $this->tryUpdateEnvFile($secretKey);
            }

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Failed to setup PayMongo webhook: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Attempt to automatically update .env file if writable.
     */
    private function tryUpdateEnvFile(string $secretKey): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath) || !is_writable($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            return;
        }

        if (preg_match('/^PAYMONGO_WEBHOOK_SECRET_KEY_TEST=.*$/m', $content)) {
            $updated = preg_replace(
                '/^PAYMONGO_WEBHOOK_SECRET_KEY_TEST=.*$/m',
                'PAYMONGO_WEBHOOK_SECRET_KEY_TEST=' . $secretKey,
                $content
            );
        } else {
            $updated = $content . "\nPAYMONGO_WEBHOOK_SECRET_KEY_TEST=" . $secretKey . "\n";
        }

        if ($updated !== null && file_put_contents($envPath, $updated) !== false) {
            $this->newLine();
            $this->info('✓ Automatically updated PAYMONGO_WEBHOOK_SECRET_KEY_TEST in .env file!');
        }
    }
}


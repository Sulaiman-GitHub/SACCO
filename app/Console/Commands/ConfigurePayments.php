<?php

namespace App\Console\Commands;

use App\Models\Sacco;
use App\Models\SaccoPaymentSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ConfigurePayments extends Command
{
    /**
     * @var string
     */
    protected $signature = 'payments:configure {sacco_id : The SACCO to set up}
        {--provider= : flutterwave or fake (defaults to PAYMENTS_DRIVER)}
        {--environment= : sandbox or live (defaults to PAYMENTS_ENVIRONMENT)}
        {--generate-webhook-hash : Make up a new webhook secret hash and print it once, to paste into the Flutterwave dashboard}
        {--disable : Store the settings but switch online payments off}';

    /**
     * @var string
     */
    protected $description = 'Store a SACCO\'s payment provider credentials (read from FLW_V3_* env vars, or asked for) and switch online payments on';

    public function handle(): int
    {
        $sacco = Sacco::find((int) $this->argument('sacco_id'));

        if ($sacco === null) {
            $this->error('No SACCO with that id.');

            return self::FAILURE;
        }

        $provider = (string) ($this->option('provider') ?: config('payments.driver'));
        $environment = (string) ($this->option('environment') ?: config('payments.environment'));

        if (! in_array($provider, ['flutterwave', 'fake'], true) || ! in_array($environment, ['sandbox', 'live'], true)) {
            $this->error('Provider must be flutterwave or fake, and environment sandbox or live.');

            return self::FAILURE;
        }

        if ($environment === 'live' && ! config('payments.allow_live')) {
            $this->error('Live payments are not enabled in this build (PAYMENTS_ALLOW_LIVE). Use the sandbox.');

            return self::FAILURE;
        }

        $credentials = [];
        $webhookSecret = null;
        $generated = false;

        if ($provider === 'flutterwave') {
            $credentials = [
                'public_key' => $this->fromEnvOrAsk(['FLW_V3_PUBLIC_KEY', 'PUBLIC_KEY'], 'Flutterwave public key'),
                'secret_key' => $this->fromEnvOrAsk(['FLW_V3_SECRET_KEY', 'SECRET_KEY'], 'Flutterwave secret key'),
                'encryption_key' => $this->fromEnvOrAsk(['FLW_V3_ENCRYPTION_KEY', 'ENCRYPTION_KEY'], 'Flutterwave encryption key', required: false),
            ];

            if ($credentials['secret_key'] === '') {
                $this->error('A secret key is required.');

                return self::FAILURE;
            }

            if ($environment === 'sandbox' && ! str_starts_with($credentials['secret_key'], 'FLWSECK_TEST')) {
                $this->error('The sandbox needs a Flutterwave TEST secret key (it starts with FLWSECK_TEST).');

                return self::FAILURE;
            }

            if ($this->option('generate-webhook-hash')) {
                $webhookSecret = Str::random(40);
                $generated = true;
            } else {
                $webhookSecret = $this->fromEnvOrAsk(['FLW_V3_WEBHOOK_HASH'], 'Flutterwave webhook secret hash (the same value set in the dashboard)');
            }

            if ($webhookSecret === '') {
                $this->error('A webhook secret hash is required so payment confirmations can be trusted.');

                return self::FAILURE;
            }
        }

        SaccoPaymentSetting::withoutTenantScope()->updateOrCreate(
            ['sacco_id' => $sacco->id],
            [
                'provider' => $provider,
                'environment' => $environment,
                'credentials' => $credentials,
                'webhook_secret' => $webhookSecret,
                'enabled' => ! $this->option('disable'),
            ],
        );

        $this->info("Payments for \"{$sacco->name}\": {$provider} ({$environment}), ".($this->option('disable') ? 'switched OFF.' : 'switched ON.'));

        if ($provider === 'flutterwave') {
            $this->line('Webhook URL to register in the Flutterwave dashboard: '.url('/webhooks/flutterwave/'.$sacco->id));

            if ($generated) {
                $this->warn('New webhook secret hash (shown once, put it in the dashboard and in FLW_V3_WEBHOOK_HASH): '.$webhookSecret);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $envNames  First one set wins
     */
    private function fromEnvOrAsk(array $envNames, string $label, bool $required = true): string
    {
        foreach ($envNames as $name) {
            $value = env($name);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (! $this->input->isInteractive()) {
            return '';
        }

        return trim((string) $this->secret($label.($required ? '' : ' (optional)')));
    }
}

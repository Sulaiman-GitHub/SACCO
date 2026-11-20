<?php

namespace App\Console\Commands;

use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePayments extends Command
{
    /**
     * @var string
     */
    protected $signature = 'payments:reconcile';

    /**
     * @var string
     */
    protected $description = 'Re-check unfinished online payments with the provider (recovers missed webhooks) and expire stale ones';

    public function handle(PaymentProcessor $processor): int
    {
        $checked = 0;
        $recovered = 0;
        $expired = 0;

        PaymentTransaction::withoutTenantScope()
            ->whereIn('status', PaymentTransaction::OPEN_STATUSES)
            ->where('created_at', '<=', now()->subMinutes((int) config('payments.reconcile_after_minutes')))
            ->orderBy('id')
            ->each(function (PaymentTransaction $payment) use ($processor, &$checked, &$recovered, &$expired) {
                $checked++;

                try {
                    $payment = $processor->verifyAndSettle($payment);

                    if ($payment->isSuccessful()) {
                        $recovered++;

                        return;
                    }

                    if ($processor->expireIfStale($payment)->status === 'expired') {
                        $expired++;
                    }
                } catch (Throwable $e) {
                    // One bad payment must not stop the rest. The message is logged without any credentials.
                    report($e);
                }
            });

        $this->info("Checked {$checked} payment(s): {$recovered} recorded, {$expired} expired.");

        return self::SUCCESS;
    }
}

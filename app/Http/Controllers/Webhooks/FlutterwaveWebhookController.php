<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\Sacco;
use App\Models\SaccoPaymentSetting;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Flutterwave events. Nothing in the body is trusted: the signature header is checked,
 * OUR payment row is loaded by reference inside this SACCO, and the result is confirmed with the
 * provider's verify API before anything is recorded.
 */
class FlutterwaveWebhookController extends Controller
{
    public function __invoke(Request $request, Sacco $sacco, PaymentProcessor $processor): JsonResponse
    {
        $settings = SaccoPaymentSetting::withoutTenantScope()
            ->where('sacco_id', $sacco->id)
            ->where('provider', 'flutterwave')
            ->where('enabled', true)
            ->first();

        abort_if($settings === null, 404);

        $secret = (string) $settings->webhook_secret;
        $signature = $request->header('verif-hash');

        if ($secret === '' || ! is_string($signature) || $signature === '' || ! hash_equals($secret, $signature)) {
            Log::warning('Flutterwave webhook rejected: bad signature', ['sacco_id' => $sacco->id]);

            return response()->json(['ok' => false], 401);
        }

        $txRef = $request->input('data.tx_ref');
        $event = $request->input('event');

        if ($event !== 'charge.completed' || ! is_string($txRef) || $txRef === '') {
            Log::info('Flutterwave webhook ignored: unsupported event', ['sacco_id' => $sacco->id, 'event' => is_string($event) ? $event : null]);

            return response()->json(['ok' => true]);
        }

        // Always inside this SACCO: another SACCO's webhook can never touch our payments
        $payment = PaymentTransaction::withoutTenantScope()
            ->where('sacco_id', $sacco->id)
            ->where('tx_ref', $txRef)
            ->first();

        if ($payment === null) {
            Log::warning('Flutterwave webhook ignored: unknown payment reference', ['sacco_id' => $sacco->id]);

            return response()->json(['ok' => true]);
        }

        $providerId = $request->input('data.id');
        $processor->verifyAndSettle($payment, is_scalar($providerId) ? (string) $providerId : null);

        return response()->json(['ok' => true]);
    }
}

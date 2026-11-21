<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Payment flow (SANDBOX ONLY for the academic version).
 *
 * 1. Student calls initiate() -> gets a pending payment + a gateway URL.
 * 2. Gateway redirects back / calls callback() with the result.
 * 3. Accounts staff can also verify cash or manual payments.
 *
 * Replace the TODO blocks with the real bKash Tokenized Checkout or
 * SSLCommerz SDK calls when moving to production.
 */
class PaymentController extends Controller
{
    public function initiate(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('pay', $invoice);
        abort_if($invoice->status === 'paid', 422, 'This invoice is already paid.');

        $data = $request->validate([
            'method' => ['required', 'in:bkash,sslcommerz'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:'.$invoice->due_amount],
        ]);

        $payment = $invoice->payments()->create([
            'student_id' => $request->user()->id,
            'amount' => $data['amount'] ?? $invoice->due_amount,
            'method' => $data['method'],
            'trx_id' => 'US'.now()->format('ymd').Str::upper(Str::random(8)),
            'status' => 'pending',
        ]);

        // TODO: call the gateway's create-payment API and return its checkout URL.
        $checkoutUrl = url("/api/payments/{$payment->trx_id}/sandbox-callback?status=success");

        return response()->json(['payment' => $payment, 'checkout_url' => $checkoutUrl], 201);
    }

    /** Gateway callback. In sandbox mode, ?status=success|failed simulates the result. */
    public function callback(Request $request, string $trxId): JsonResponse
    {
        abort_unless(config('unisphere.payment_sandbox'), 404);

        $payment = Payment::where('trx_id', $trxId)->where('status', 'pending')->firstOrFail();

        // TODO: verify the gateway signature / query the gateway for the real status.
        $success = $request->query('status') === 'success';

        $payment->update([
            'status' => $success ? 'success' : 'failed',
            'paid_at' => $success ? now() : null,
        ]);
        $payment->invoice->refreshStatus();

        return response()->json(['message' => $success ? 'Payment successful.' : 'Payment failed.', 'payment' => $payment]);
    }

    /** Accounts staff record a cash payment at the counter. */
    public function recordCash(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:'.$invoice->due_amount]]);

        $payment = $invoice->payments()->create([
            'student_id' => $invoice->student_id,
            'amount' => $data['amount'],
            'method' => 'cash',
            'trx_id' => 'CASH'.now()->format('ymdHis').Str::upper(Str::random(4)),
            'status' => 'success',
            'paid_at' => now(),
            'verified_by' => $request->user()->id,
        ]);
        $invoice->refreshStatus();

        return response()->json($payment, 201);
    }
}

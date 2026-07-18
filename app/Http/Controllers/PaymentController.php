<?php

namespace App\Http\Controllers;

use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    /**
     * Manually record a payment against an invoice — used until real
     * M-Pesa STK Push integration exists. Only super-admin (platform
     * staff) can record payments, since this represents staff
     * confirming money was actually received.
     */
    public function store(Request $request, int $invoiceId)
    {
        if (! $this->isSuperAdmin($request)) {
            abort(403, 'Only platform staff may record payments.');
        }

        $invoice = Invoice::findOrFail($invoiceId);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:mpesa_manual,mpesa_stk,bank_transfer,other',
            'reference' => 'nullable|string|max:255',
        ]);

        $recordedBy = $request->attributes->get('identity_user')['email'] ?? 'unknown';

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'tenant_id' => $invoice->tenant_id,
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'reference' => $validated['reference'] ?? null,
            'recorded_by' => $recordedBy,
            'paid_at' => now(),
        ]);

        $totalPaid = Payment::where('invoice_id', $invoice->id)->sum('amount');

        if ($totalPaid >= $invoice->amount) {
            $invoice->update(['status' => 'paid']);

            // Unblock the tenant if this was the invoice keeping them blocked.
            $account = BillingAccount::where('tenant_id', $invoice->tenant_id)->first();
            $stillHasOverdueInvoices = Invoice::where('tenant_id', $invoice->tenant_id)
                ->where('status', 'overdue')
                ->exists();

            if ($account && $account->status === 'blocked' && ! $stillHasOverdueInvoices) {
                $account->update(['status' => 'active']);
            }
        }

        return response()->json([
            'payment' => $payment,
            'invoice' => $invoice->fresh(),
        ], 201);
    }
}

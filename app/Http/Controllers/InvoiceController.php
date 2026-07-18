<?php

namespace App\Http\Controllers;

use App\Models\BillingAccount;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    public function index(Request $request)
    {
        $query = Invoice::query()->with('payments');

        if (! $this->isSuperAdmin($request)) {
            $query->where('tenant_id', $this->tenantId($request));
        }

        return response()->json(['invoices' => $query->latest('created_at')->get()]);
    }

    public function show(Request $request, int $id)
    {
        $invoice = Invoice::with('payments')->findOrFail($id);

        if (! $this->isSuperAdmin($request) && $invoice->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    public function billingStatus(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $account = BillingAccount::where('tenant_id', $tenantId)->first();

        if (! $account) {
            return response()->json(['message' => 'No billing account found for this tenant.'], 404);
        }

        $outstanding = Invoice::where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('amount');

        return response()->json([
            'billing_account' => $account,
            'outstanding_balance' => $outstanding,
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\Device;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class BillingService
{
    const RATE_PER_DEVICE_PER_MONTH = 500.00;
    const TRIAL_DAYS = 7;
    const CYCLE_DAYS = 30;

    /**
     * Get or create a tenant's billing account. On first creation,
     * queries Identity for the tenant's real creation date — the
     * trial and billing anchor are both derived from that, not from
     * whenever NOC happens to first see this tenant.
     */
    public function getOrCreateAccount(int $tenantId): ?BillingAccount
    {
        $existing = BillingAccount::where('tenant_id', $tenantId)->first();
        if ($existing) {
            return $existing;
        }

        $tenantCreatedAt = $this->fetchTenantCreatedAt($tenantId);
        if (! $tenantCreatedAt) {
            Log::error("Could not fetch tenant {$tenantId} creation date from Identity — billing account not created.");
            return null;
        }

        $trialEndsAt = $tenantCreatedAt->copy()->addDays(self::TRIAL_DAYS);

        return BillingAccount::create([
            'tenant_id' => $tenantId,
            'tenant_created_at' => $tenantCreatedAt,
            'trial_ends_at' => $trialEndsAt,
            'billing_anchor_date' => $trialEndsAt,
            'status' => 'trial',
        ]);
    }

    protected function fetchTenantCreatedAt(int $tenantId): ?Carbon
    {
        $identityUrl = config('services.identity.url');
        $serviceToken = config('services.identity.service_token');

        $response = Http::withToken($serviceToken)
            ->timeout(5)
            ->get("{$identityUrl}/tenants/{$tenantId}");

        if (! $response->successful()) {
            return null;
        }

        $createdAt = $response->json('tenant.created_at');
        return $createdAt ? Carbon::parse($createdAt) : null;
    }

    /**
     * Called when a device is added, after the tenant's trial has
     * ended. Creates a prorated invoice covering only the days
     * remaining until the next billing anchor date.
     */
    public function chargeProratedForNewDevice(int $tenantId): ?Invoice
    {
        $account = $this->getOrCreateAccount($tenantId);
        if (! $account || $account->status === 'trial') {
            // Still in trial — no charge yet.
            return null;
        }

        $now = now();
        $nextAnchor = $this->nextAnchorAfter($account->billing_anchor_date, $now);
        $daysRemaining = max(1, $now->diffInDays($nextAnchor, absolute: true));

        $ratePerDay = self::RATE_PER_DEVICE_PER_MONTH / self::CYCLE_DAYS;
        $amount = round($ratePerDay * $daysRemaining, 2);

        return Invoice::create([
            'tenant_id' => $tenantId,
            'type' => 'prorated',
            'device_count' => 1,
            'amount' => $amount,
            'period_start' => $now->toDateString(),
            'period_end' => $nextAnchor->toDateString(),
            'due_at' => $now->copy()->addDays(3),
            'status' => 'pending',
        ]);
    }

    /**
     * Find the next occurrence of the anchor date on or after $from —
     * anchor date recurs monthly on the same day-of-month.
     */
    protected function nextAnchorAfter(Carbon $anchorDate, Carbon $from): Carbon
    {
        $next = $anchorDate->copy();

        while ($next->lessThanOrEqualTo($from)) {
            $next->addDays(self::CYCLE_DAYS);
        }

        return $next;
    }

    /**
     * Generate the consolidated monthly invoice for every billing
     * account whose anchor date has arrived. Intended to run daily
     * via the scheduler — only accounts whose anchor is due today
     * actually get an invoice.
     */
    public function generateDueRecurringInvoices(): int
    {
        $today = now()->startOfDay();
        $count = 0;

        $dueAccounts = BillingAccount::whereIn('status', ['trial', 'active'])
            ->whereDate('billing_anchor_date', '<=', $today)
            ->get();

        foreach ($dueAccounts as $account) {
            $deviceCount = Device::where('tenant_id', $account->tenant_id)->count();

            if ($deviceCount > 0) {
                Invoice::create([
                    'tenant_id' => $account->tenant_id,
                    'type' => 'recurring',
                    'device_count' => $deviceCount,
                    'amount' => round($deviceCount * self::RATE_PER_DEVICE_PER_MONTH, 2),
                    'period_start' => $account->billing_anchor_date->toDateString(),
                    'period_end' => $account->billing_anchor_date->copy()->addDays(self::CYCLE_DAYS)->toDateString(),
                    'due_at' => $account->billing_anchor_date->copy()->addDays(3),
                    'status' => 'pending',
                ]);
                $count++;
            }

            $account->update([
                'billing_anchor_date' => $account->billing_anchor_date->copy()->addDays(self::CYCLE_DAYS),
                'status' => $deviceCount > 0 ? 'active' : $account->status,
            ]);
        }

        return $count;
    }

    /**
     * Mark accounts overdue/blocked based on unpaid invoices past
     * their due date. Intended to run daily.
     */
    public function processOverdueAccounts(): int
    {
        $blockedCount = 0;

        $overdueInvoiceTenantIds = Invoice::where('status', 'pending')
            ->where('due_at', '<', now())
            ->pluck('tenant_id')
            ->unique();

        foreach ($overdueInvoiceTenantIds as $tenantId) {
            Invoice::where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->where('due_at', '<', now())
                ->update(['status' => 'overdue']);

            $account = BillingAccount::where('tenant_id', $tenantId)->first();
            if ($account && $account->status !== 'blocked') {
                $account->update(['status' => 'blocked']);
                $blockedCount++;
            }
        }

        return $blockedCount;
    }

    public function isTenantBlocked(int $tenantId): bool
    {
        $account = BillingAccount::where('tenant_id', $tenantId)->first();
        return $account && $account->status === 'blocked';
    }
}

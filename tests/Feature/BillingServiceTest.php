<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Device;
use App\Models\Invoice;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingService();
    }

    protected function fakeIdentityTenant(int $tenantId, Carbon $createdAt): void
    {
        Http::fake([
            "*/api/v1/tenants/{$tenantId}" => Http::response([
                'tenant' => [
                    'id' => $tenantId,
                    'name' => 'Test Tenant',
                    'created_at' => $createdAt->toIso8601String(),
                ],
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creates_billing_account_with_trial_derived_from_identity(): void
    {
        $createdAt = Carbon::parse('2026-01-01 00:00:00');
        $this->fakeIdentityTenant(5, $createdAt);

        $account = $this->service->getOrCreateAccount(5);

        $this->assertNotNull($account);
        $this->assertSame('trial', $account->status);
        $this->assertSame('2026-01-08 00:00:00', $account->trial_ends_at->toDateTimeString());
    }

    public function test_returns_existing_account_without_calling_identity_again(): void
    {
        $createdAt = Carbon::parse('2026-01-01 00:00:00');
        $this->fakeIdentityTenant(5, $createdAt);

        $first = $this->service->getOrCreateAccount(5);
        $second = $this->service->getOrCreateAccount(5);

        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    public function test_returns_null_and_logs_when_identity_is_unreachable(): void
    {
        Http::fake([
            '*/api/v1/tenants/*' => Http::response(['message' => 'error'], 500),
        ]);

        $account = $this->service->getOrCreateAccount(99);

        $this->assertNull($account);
    }

    public function test_no_charge_for_device_added_during_trial(): void
    {
        $createdAt = Carbon::parse('2026-01-01 00:00:00');
        $this->fakeIdentityTenant(5, $createdAt);
        Carbon::setTestNow(Carbon::parse('2026-01-03 00:00:00')); // still within 7-day trial

        $invoice = $this->service->chargeProratedForNewDevice(5);

        $this->assertNull($invoice);
        $this->assertSame(0, Invoice::count());
    }

    public function test_prorated_charge_calculated_correctly_after_trial(): void
    {
        BillingAccount::create([
            'tenant_id' => 5,
            'tenant_created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'trial_ends_at' => Carbon::parse('2026-01-08 00:00:00'),
            'billing_anchor_date' => Carbon::parse('2026-01-08 00:00:00'),
            'status' => 'active',
        ]);

        // 10 days before the next anchor (Jan 18 = Jan 8 + 30 days from... actually
        // anchor recurs every 30 days from Jan 8, so next occurrence after "now" matters).
        // Set "now" to exactly 10 days before the anchor date to get a clean, predictable delta.
        Carbon::setTestNow(Carbon::parse('2026-01-08 00:00:00')->addDays(20));

        $invoice = $this->service->chargeProratedForNewDevice(5);

        $this->assertNotNull($invoice);
        $this->assertSame('prorated', $invoice->type);
        $this->assertSame(1, $invoice->device_count);
        // 10 days remaining in the 30-day cycle at (500/30) per day.
        $this->assertEqualsWithDelta(166.67, (float) $invoice->amount, 0.01);
    }

    public function test_generates_recurring_invoice_when_anchor_date_has_passed(): void
    {
        Device::create(['tenant_id' => 5, 'name' => 'D1', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up']);
        Device::create(['tenant_id' => 5, 'name' => 'D2', 'ip_address' => '10.0.0.2', 'type' => 'router', 'status' => 'up']);

        BillingAccount::create([
            'tenant_id' => 5,
            'tenant_created_at' => Carbon::parse('2026-01-01'),
            'trial_ends_at' => Carbon::parse('2026-01-08'),
            'billing_anchor_date' => now()->subDay(),
            'status' => 'active',
        ]);

        $count = $this->service->generateDueRecurringInvoices();

        $this->assertSame(1, $count);
        $invoice = Invoice::where('tenant_id', 5)->first();
        $this->assertSame(2, $invoice->device_count);
        $this->assertEqualsWithDelta(1000.00, (float) $invoice->amount, 0.01);
    }

    public function test_no_recurring_invoice_generated_for_tenant_with_zero_devices(): void
    {
        BillingAccount::create([
            'tenant_id' => 5,
            'tenant_created_at' => Carbon::parse('2026-01-01'),
            'trial_ends_at' => Carbon::parse('2026-01-08'),
            'billing_anchor_date' => now()->subDay(),
            'status' => 'active',
        ]);

        $count = $this->service->generateDueRecurringInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    public function test_overdue_unpaid_invoice_blocks_the_tenant(): void
    {
        BillingAccount::create([
            'tenant_id' => 5,
            'tenant_created_at' => Carbon::parse('2026-01-01'),
            'trial_ends_at' => Carbon::parse('2026-01-08'),
            'billing_anchor_date' => now(),
            'status' => 'active',
        ]);

        Invoice::create([
            'tenant_id' => 5,
            'type' => 'recurring',
            'device_count' => 1,
            'amount' => 500,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'due_at' => now()->subDay(),
            'status' => 'pending',
        ]);

        $blockedCount = $this->service->processOverdueAccounts();

        $this->assertSame(1, $blockedCount);
        $this->assertTrue($this->service->isTenantBlocked(5));
    }

    public function test_tenant_not_blocked_while_invoice_is_not_yet_due(): void
    {
        BillingAccount::create([
            'tenant_id' => 5,
            'tenant_created_at' => Carbon::parse('2026-01-01'),
            'trial_ends_at' => Carbon::parse('2026-01-08'),
            'billing_anchor_date' => now(),
            'status' => 'active',
        ]);

        Invoice::create([
            'tenant_id' => 5,
            'type' => 'recurring',
            'device_count' => 1,
            'amount' => 500,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'due_at' => now()->addDays(3),
            'status' => 'pending',
        ]);

        $blockedCount = $this->service->processOverdueAccounts();

        $this->assertSame(0, $blockedCount);
        $this->assertFalse($this->service->isTenantBlocked(5));
    }
}

<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], string $token = 'fake-token'): string
    {
        Http::fake([
            '*/api/v1/me' => Http::response([
                'user' => ['id' => 1, 'tenant_id' => $tenantId, 'name' => 'Test', 'email' => 'test@example.com', 'status' => 'active'],
                'roles' => $roles,
                'permissions' => [],
            ], 200),
        ]);

        return $token;
    }

    protected function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    protected function makeInvoice(int $tenantId, float $amount = 500.00, string $status = 'pending'): Invoice
    {
        return Invoice::create([
            'tenant_id' => $tenantId,
            'type' => 'recurring',
            'device_count' => 1,
            'amount' => $amount,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'due_at' => now()->addDays(3),
            'status' => $status,
        ]);
    }

    // --- Invoice visibility ---

    public function test_user_only_sees_own_tenants_invoices(): void
    {
        $this->makeInvoice(tenantId: 1);
        $this->makeInvoice(tenantId: 2);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson('/api/v1/invoices', $this->authHeader($token));

        $this->assertCount(1, $response->json('invoices'));
    }

    public function test_user_cannot_view_invoice_from_different_tenant(): void
    {
        $invoice = $this->makeInvoice(tenantId: 2);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}", $this->authHeader($token));

        $response->assertStatus(404);
    }

    public function test_super_admin_sees_invoices_across_all_tenants(): void
    {
        $this->makeInvoice(tenantId: 1);
        $this->makeInvoice(tenantId: 2);

        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $response = $this->getJson('/api/v1/invoices', $this->authHeader($token));

        $this->assertCount(2, $response->json('invoices'));
    }

    // --- Payment recording authorization ---

    public function test_regular_user_cannot_record_payments(): void
    {
        $invoice = $this->makeInvoice(tenantId: 1);
        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['tenant-admin']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 500,
            'method' => 'mpesa_manual',
        ], $this->authHeader($token));

        $response->assertStatus(403);
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_super_admin_can_record_a_full_payment(): void
    {
        $invoice = $this->makeInvoice(tenantId: 1, amount: 500.00);
        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 500.00,
            'method' => 'mpesa_manual',
            'reference' => 'ABC123',
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('invoice.status', 'paid');
    }

    // --- Partial payment handling (not covered by manual testing) ---

    public function test_partial_payment_does_not_mark_invoice_paid(): void
    {
        $invoice = $this->makeInvoice(tenantId: 1, amount: 1000.00);
        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 400.00,
            'method' => 'mpesa_manual',
        ], $this->authHeader($token));

        $response->assertStatus(201);
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_two_partial_payments_summing_to_full_amount_marks_invoice_paid(): void
    {
        $invoice = $this->makeInvoice(tenantId: 1, amount: 1000.00);
        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 400.00, 'method' => 'mpesa_manual',
        ], $this->authHeader($token));

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 600.00, 'method' => 'mpesa_manual',
        ], $this->authHeader($token));

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    // --- Full unblock lifecycle via the controller layer ---

    public function test_paying_off_overdue_invoice_unblocks_the_tenant(): void
    {
        BillingAccount::create([
            'tenant_id' => 1,
            'tenant_created_at' => now()->subDays(40),
            'trial_ends_at' => now()->subDays(33),
            'billing_anchor_date' => now(),
            'status' => 'blocked',
        ]);
        $invoice = $this->makeInvoice(tenantId: 1, amount: 500.00, status: 'overdue');

        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 500.00, 'method' => 'mpesa_manual',
        ], $this->authHeader($token));

        $account = BillingAccount::where('tenant_id', 1)->first();
        $this->assertSame('active', $account->status);
    }

    public function test_paying_one_overdue_invoice_does_not_unblock_if_another_is_still_overdue(): void
    {
        BillingAccount::create([
            'tenant_id' => 1,
            'tenant_created_at' => now()->subDays(70),
            'trial_ends_at' => now()->subDays(63),
            'billing_anchor_date' => now(),
            'status' => 'blocked',
        ]);
        $invoiceA = $this->makeInvoice(tenantId: 1, amount: 500.00, status: 'overdue');
        $this->makeInvoice(tenantId: 1, amount: 500.00, status: 'overdue');

        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $this->postJson("/api/v1/invoices/{$invoiceA->id}/payments", [
            'amount' => 500.00, 'method' => 'mpesa_manual',
        ], $this->authHeader($token));

        $account = BillingAccount::where('tenant_id', 1)->first();
        $this->assertSame('blocked', $account->status, 'Tenant should remain blocked while a second overdue invoice exists.');
    }

    // --- Billing status endpoint ---

    public function test_billing_status_returns_correct_outstanding_balance(): void
    {
        BillingAccount::create([
            'tenant_id' => 1,
            'tenant_created_at' => now()->subDays(10),
            'trial_ends_at' => now()->subDays(3),
            'billing_anchor_date' => now(),
            'status' => 'active',
        ]);
        $this->makeInvoice(tenantId: 1, amount: 300.00, status: 'pending');
        $this->makeInvoice(tenantId: 1, amount: 200.00, status: 'overdue');
        $this->makeInvoice(tenantId: 1, amount: 500.00, status: 'paid'); // should NOT count

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson('/api/v1/billing/status', $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertEquals(500.00, (float) $response->json('outstanding_balance'));
    }

    public function test_billing_status_404_when_no_billing_account_exists(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 999);

        $response = $this->getJson('/api/v1/billing/status', $this->authHeader($token));

        $response->assertStatus(404);
    }
}

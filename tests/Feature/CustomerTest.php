<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], string $token = 'fake-test-token'): string
    {
        Http::fake([
            '*/api/v1/me' => Http::response([
                'user' => ['id' => 1, 'tenant_id' => $tenantId, 'name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active'],
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/customers');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_create_a_customer(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Jane Mwangi',
            'phone' => '0722123456',
            'email' => 'jane@example.com',
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('customer.name', 'Jane Mwangi')
            ->assertJsonPath('customer.tenant_id', 1)
            ->assertJsonPath('customer.status', 'active');

        $this->assertDatabaseHas('customers', ['name' => 'Jane Mwangi', 'tenant_id' => 1]);
    }

    public function test_customer_is_created_under_the_callers_tenant_regardless_of_request_body(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 5);

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Sneaky Customer',
            'tenant_id' => 999,
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('customer.tenant_id', 5);
    }

    public function test_user_only_sees_customers_in_own_tenant(): void
    {
        Customer::create(['tenant_id' => 1, 'name' => 'Tenant 1 Customer', 'status' => 'active']);
        Customer::create(['tenant_id' => 2, 'name' => 'Tenant 2 Customer', 'status' => 'active']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson('/api/v1/customers', $this->authHeader($token));

        $names = collect($response->json('customers'))->pluck('name');
        $this->assertTrue($names->contains('Tenant 1 Customer'));
        $this->assertFalse($names->contains('Tenant 2 Customer'));
    }

    public function test_user_cannot_view_customer_from_different_tenant(): void
    {
        $customer = Customer::create(['tenant_id' => 2, 'name' => 'Other Tenant Customer', 'status' => 'active']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson("/api/v1/customers/{$customer->id}", $this->authHeader($token));

        $response->assertStatus(404);
    }

    public function test_linking_a_device_in_same_tenant_succeeds(): void
    {
        $customer = Customer::create(['tenant_id' => 1, 'name' => 'Jane', 'status' => 'active']);
        $device = Device::create(['tenant_id' => 1, 'name' => 'Router', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/devices", [
            'device_id' => $device->id,
        ], $this->authHeader($token));

        $response->assertStatus(200)
            ->assertJsonPath('device.customer_id', $customer->id);
    }

    public function test_linking_a_device_from_a_different_tenant_is_rejected(): void
    {
        $customer = Customer::create(['tenant_id' => 1, 'name' => 'Jane', 'status' => 'active']);
        $otherTenantDevice = Device::create(['tenant_id' => 2, 'name' => 'Other Router', 'ip_address' => '10.0.1.1', 'type' => 'router', 'status' => 'up']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/devices", [
            'device_id' => $otherTenantDevice->id,
        ], $this->authHeader($token));

        $response->assertStatus(422);
        $this->assertNull($otherTenantDevice->fresh()->customer_id);
    }

    public function test_deleting_customer_does_not_delete_their_linked_device(): void
    {
        $customer = Customer::create(['tenant_id' => 1, 'name' => 'Jane', 'status' => 'active']);
        $device = Device::create(['tenant_id' => 1, 'name' => 'Router', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up', 'customer_id' => $customer->id]);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], $this->authHeader($token));
        $response->assertStatus(200);

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertNull($device->fresh()->customer_id);
    }

    public function test_updating_customer_status_works(): void
    {
        $customer = Customer::create(['tenant_id' => 1, 'name' => 'Jane', 'status' => 'active']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->patchJson("/api/v1/customers/{$customer->id}", [
            'status' => 'suspended',
        ], $this->authHeader($token));

        $response->assertStatus(200)
            ->assertJsonPath('customer.status', 'suspended');
    }
}

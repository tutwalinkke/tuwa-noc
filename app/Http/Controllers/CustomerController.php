<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    protected function resolveCustomer(Request $request, int $id): Customer
    {
        $customer = Customer::findOrFail($id);

        if (! $this->isSuperAdmin($request) && $customer->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return $customer;
    }

    public function index(Request $request)
    {
        $query = Customer::query()->withCount('devices');

        if (! $this->isSuperAdmin($request)) {
            $query->where('tenant_id', $this->tenantId($request));
        }

        return response()->json(['customers' => $query->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'service_address' => 'nullable|string|max:255',
        ]);

        $customer = Customer::create([
            ...$validated,
            'tenant_id' => $this->tenantId($request),
            'status' => 'active',
        ]);

        ActivityLogger::log($request, "Customer created: {$customer->name}", ['type' => 'Customer', 'id' => $customer->id]);

        return response()->json(['customer' => $customer], 201);
    }

    public function show(Request $request, int $id)
    {
        $customer = $this->resolveCustomer($request, $id);

        return response()->json([
            'customer' => $customer,
            'devices' => $customer->devices()->get(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $customer = $this->resolveCustomer($request, $id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'service_address' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,suspended,cancelled',
        ]);

        $customer->update($validated);

        ActivityLogger::log($request, "Customer updated: {$customer->name}", ['type' => 'Customer', 'id' => $customer->id]);

        return response()->json(['customer' => $customer->fresh()]);
    }

    public function destroy(Request $request, int $id)
    {
        $customer = $this->resolveCustomer($request, $id);
        $name = $customer->name;
        $customer->delete();

        ActivityLogger::log($request, "Customer deleted: {$name}");

        return response()->json(['message' => 'Customer deleted.']);
    }

    /**
     * Link a device to this customer. Both must belong to the same
     * tenant — same defensive pattern used in SubnetController's
     * device-linking to prevent cross-tenant assignment.
     */
    public function linkDevice(Request $request, int $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $validated = $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
        ]);

        $device = \App\Models\Device::find($validated['device_id']);

        if (! $device || $device->tenant_id !== $customer->tenant_id) {
            return response()->json(['message' => 'Device not found in this tenant.'], 422);
        }

        $device->update(['customer_id' => $customer->id]);

        ActivityLogger::log($request, "Device '{$device->name}' linked to customer {$customer->name}", ['type' => 'Device', 'id' => $device->id]);

        return response()->json(['device' => $device->fresh()]);
    }
}

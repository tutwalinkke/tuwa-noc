<?php

namespace App\Http\Controllers;

use App\Models\Subnet;
use App\Services\ActivityLogger;
use App\Services\SubnetService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SubnetController extends Controller
{
    public function __construct(protected SubnetService $subnetService) {}

    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    protected function resolveSubnet(Request $request, int $id): Subnet
    {
        $subnet = Subnet::findOrFail($id);

        if (! $this->isSuperAdmin($request) && $subnet->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return $subnet;
    }

    public function index(Request $request)
    {
        $query = Subnet::query()->withCount([
            'ipAddresses',
            'ipAddresses as allocated_count' => fn ($q) => $q->where('status', 'allocated'),
            'ipAddresses as available_count' => fn ($q) => $q->where('status', 'available'),
        ]);

        if (! $this->isSuperAdmin($request)) {
            $query->where('tenant_id', $this->tenantId($request));
        }

        return response()->json(['subnets' => $query->orderBy('cidr')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'cidr' => 'required|string',
            'description' => 'nullable|string|max:255',
            'site' => 'nullable|string|max:255',
            'vlan_id' => 'nullable|string|max:255',
        ]);

        try {
            $subnet = $this->subnetService->createSubnetWithAddresses(
                tenantId: $this->tenantId($request),
                cidr: $validated['cidr'],
                description: $validated['description'] ?? null,
                site: $validated['site'] ?? null,
                vlanId: $validated['vlan_id'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLogger::log($request, "Subnet created: {$subnet->cidr}", ['type' => 'Subnet', 'id' => $subnet->id]);

        return response()->json([
            'subnet' => $subnet,
            'addresses_generated' => $subnet->ipAddresses()->count(),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $subnet = $this->resolveSubnet($request, $id);

        return response()->json([
            'subnet' => $subnet,
            'addresses' => $subnet->ipAddresses()->orderBy('address')->get(),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $subnet = $this->resolveSubnet($request, $id);
        $cidr = $subnet->cidr;
        $subnet->delete();

        ActivityLogger::log($request, "Subnet deleted: {$cidr}");

        return response()->json(['message' => 'Subnet deleted.']);
    }

    public function allocate(Request $request, int $id)
    {
        $subnet = $this->resolveSubnet($request, $id);

        $validated = $request->validate([
            'device_id' => 'nullable|integer|exists:devices,id',
            'label' => 'nullable|string|max:255',
        ]);

        if (isset($validated['device_id'])) {
            $device = \App\Models\Device::find($validated['device_id']);
            if (! $device || $device->tenant_id !== $subnet->tenant_id) {
                return response()->json(['message' => 'Device not found in this tenant.'], 422);
            }
        }

        $ip = $this->subnetService->allocateNextAvailable(
            $subnet,
            $validated['device_id'] ?? null,
            $validated['label'] ?? null,
        );

        if (! $ip) {
            return response()->json(['message' => 'No available IP addresses in this subnet.'], 409);
        }

        ActivityLogger::log($request, "IP allocated: {$ip->address} in {$subnet->cidr}", ['type' => 'IpAddress', 'id' => $ip->id]);

        return response()->json(['ip_address' => $ip], 201);
    }

    public function release(Request $request, int $subnetId, int $ipId)
    {
        $subnet = $this->resolveSubnet($request, $subnetId);

        $ip = $subnet->ipAddresses()->findOrFail($ipId);
        $address = $ip->address;
        $ip->update(['status' => 'available', 'device_id' => null, 'label' => null]);

        ActivityLogger::log($request, "IP released: {$address} in {$subnet->cidr}");

        return response()->json(['ip_address' => $ip->fresh()]);
    }
}

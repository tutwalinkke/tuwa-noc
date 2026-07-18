<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
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
        $query = Device::query();

        if (! $this->isSuperAdmin($request)) {
            $query->where('tenant_id', $this->tenantId($request));
        }

        return response()->json([
            'devices' => $query->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|ip',
            'type' => 'required|in:router,switch,olt,server,ups,access_point,other',
            'manufacturer' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'site' => 'nullable|string|max:255',
        ]);

        $device = Device::create([
            ...$validated,
            'tenant_id' => $this->tenantId($request),
            'status' => 'unknown',
        ]);

        return response()->json(['device' => $device], 201);
    }

    protected function resolveDevice(Request $request, int $id): Device
    {
        $device = Device::findOrFail($id);

        if (! $this->isSuperAdmin($request) && $device->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return $device;
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['device' => $this->resolveDevice($request, $id)]);
    }

    public function update(Request $request, int $id)
    {
        $device = $this->resolveDevice($request, $id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'ip_address' => 'sometimes|ip',
            'type' => 'sometimes|in:router,switch,olt,server,ups,access_point,other',
            'manufacturer' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'site' => 'nullable|string|max:255',
        ]);

        $device->update($validated);

        return response()->json(['device' => $device->fresh()]);
    }

    public function destroy(Request $request, int $id)
    {
        $device = $this->resolveDevice($request, $id);
        $device->delete();

        return response()->json(['message' => 'Device deleted.']);
    }
}

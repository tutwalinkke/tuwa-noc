<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceLink;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class TopologyController extends Controller
{
    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    protected function resolveDevice(Request $request, int $deviceId): Device
    {
        $device = Device::findOrFail($deviceId);

        if (! $this->isSuperAdmin($request) && $device->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return $device;
    }

    /**
     * Combined view: every device this tenant can see, plus every
     * declared link between them. The frontend renders this directly
     * as a graph — nodes with their real live status, edges from the
     * links table. Not a live discovery snapshot, a declared structure
     * annotated with real current status.
     */
    public function index(Request $request)
    {
        $deviceQuery = Device::query();
        $linkQuery = DeviceLink::query();

        if (! $this->isSuperAdmin($request)) {
            $tenantId = $this->tenantId($request);
            $deviceQuery->where('tenant_id', $tenantId);
            $linkQuery->where('tenant_id', $tenantId);
        }

        return response()->json([
            'devices' => $deviceQuery->select('id', 'name', 'ip_address', 'type', 'status', 'site')->get(),
            'links' => $linkQuery->get(['id', 'device_a_id', 'device_b_id', 'link_type', 'description']),
        ]);
    }

    public function storeLink(Request $request)
    {
        $validated = $request->validate([
            'device_a_id' => 'required|integer|exists:devices,id',
            'device_b_id' => 'required|integer|exists:devices,id|different:device_a_id',
            'link_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $deviceA = $this->resolveDevice($request, $validated['device_a_id']);
        $deviceB = $this->resolveDevice($request, $validated['device_b_id']);

        if ($deviceA->tenant_id !== $deviceB->tenant_id) {
            return response()->json(['message' => 'Both devices must belong to the same tenant.'], 422);
        }

        // Always store the lower ID first, so a link declared in
        // either direction (A-to-B or B-to-A) is recognized as the
        // same connection and the unique constraint genuinely prevents
        // a duplicate rather than silently allowing a reversed one.
        [$lowId, $highId] = $deviceA->id < $deviceB->id
            ? [$deviceA->id, $deviceB->id]
            : [$deviceB->id, $deviceA->id];

        $existing = DeviceLink::where('device_a_id', $lowId)->where('device_b_id', $highId)->first();
        if ($existing) {
            return response()->json(['message' => 'A link between these devices already exists.'], 422);
        }

        $link = DeviceLink::create([
            'device_a_id' => $lowId,
            'device_b_id' => $highId,
            'tenant_id' => $deviceA->tenant_id,
            'link_type' => $validated['link_type'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        ActivityLogger::log(
            $request,
            "Topology link created: {$deviceA->name} \u{2194} {$deviceB->name}",
            ['type' => 'DeviceLink', 'id' => $link->id]
        );

        return response()->json(['link' => $link], 201);
    }

    public function destroyLink(Request $request, int $id)
    {
        $link = DeviceLink::findOrFail($id);

        if (! $this->isSuperAdmin($request) && $link->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        $description = "{$link->deviceA->name} \u{2194} {$link->deviceB->name}";
        $link->delete();

        ActivityLogger::log($request, "Topology link deleted: {$description}");

        return response()->json(['message' => 'Link deleted.']);
    }
}

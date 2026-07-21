<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\MaintenanceWindow;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class MaintenanceWindowController extends Controller
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

    public function index(Request $request)
    {
        $query = MaintenanceWindow::query()->with('device:id,name,ip_address');

        if (! $this->isSuperAdmin($request)) {
            $query->where('tenant_id', $this->tenantId($request));
        }

        // Upcoming and currently-active windows first, most relevant to
        // an operator glancing at this list — not a full historical log.
        return response()->json([
            'maintenance_windows' => $query
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'reason' => 'nullable|string|max:255',
        ]);

        $device = $this->resolveDevice($request, $validated['device_id']);

        $window = MaintenanceWindow::create([
            'device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'reason' => $validated['reason'] ?? null,
            'created_by_user_id' => $request->attributes->get('identity_user')['id'] ?? null,
        ]);

        ActivityLogger::log(
            $request,
            "Maintenance window scheduled for {$device->name}: {$window->starts_at} to {$window->ends_at}",
            ['type' => 'MaintenanceWindow', 'id' => $window->id]
        );

        return response()->json(['maintenance_window' => $window->load('device:id,name,ip_address')], 201);
    }

    protected function resolveWindow(Request $request, int $id): MaintenanceWindow
    {
        $window = MaintenanceWindow::findOrFail($id);

        if (! $this->isSuperAdmin($request) && $window->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return $window;
    }

    /**
     * End a maintenance window early — e.g. work finished sooner than
     * planned. Sets ends_at to now() rather than deleting the record,
     * preserving the historical fact that maintenance happened.
     */
    public function endEarly(Request $request, int $id)
    {
        $window = $this->resolveWindow($request, $id);

        if ($window->ends_at->isPast()) {
            return response()->json(['message' => 'This maintenance window has already ended.'], 422);
        }

        $window->update(['ends_at' => now()]);

        ActivityLogger::log(
            $request,
            "Maintenance window for {$window->device->name} ended early",
            ['type' => 'MaintenanceWindow', 'id' => $window->id]
        );

        return response()->json(['maintenance_window' => $window->fresh()->load('device:id,name,ip_address')]);
    }

    public function destroy(Request $request, int $id)
    {
        $window = $this->resolveWindow($request, $id);
        $deviceName = $window->device->name;
        $window->delete();

        ActivityLogger::log($request, "Maintenance window for {$deviceName} deleted");

        return response()->json(['message' => 'Maintenance window deleted.']);
    }
}

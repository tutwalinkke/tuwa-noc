<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    public function index(Request $request)
    {
        $isSuperAdmin = $this->isSuperAdmin($request);
        $tenantId = $this->tenantId($request);

        $deviceQuery = Device::query();
        $eventQuery = DeviceEvent::query();

        if (! $isSuperAdmin) {
            $deviceQuery->where('tenant_id', $tenantId);
            $eventQuery->where('tenant_id', $tenantId);
        }

        $devices = $deviceQuery->orderBy('name')->get();

        // Uptime % per device: proportion of logged events where the
        // device was recovering (up) vs going down, over its total
        // event history. A device with zero events (never flapped)
        // shows null — not enough history yet to compute a rate.
        $devices->each(function ($device) {
            $totalEvents = DeviceEvent::where('device_id', $device->id)->count();
            $downEvents = DeviceEvent::where('device_id', $device->id)
                ->where('new_status', 'down')
                ->count();

            $device->uptime_percent = $totalEvents > 0
                ? round((1 - ($downEvents / max($totalEvents, 1))) * 100, 1)
                : null;
        });

        $summary = [
            'devices_total' => $devices->count(),
            'devices_up' => $devices->where('status', 'up')->count(),
            'devices_down' => $devices->where('status', 'down')->count(),
            'devices_unknown' => $devices->where('status', 'unknown')->count(),
        ];

        $summary['health_percent'] = $summary['devices_total'] > 0
            ? round(($summary['devices_up'] / $summary['devices_total']) * 100, 1)
            : null;

        $eventSummary = [
            'total' => (clone $eventQuery)->count(),
            'critical' => (clone $eventQuery)->where('severity', 'critical')->count(),
            'warning' => (clone $eventQuery)->where('severity', 'warning')->count(),
            'info' => (clone $eventQuery)->where('severity', 'info')->count(),
        ];

        $latestEvent = (clone $eventQuery)->latest('created_at')->first();
        $eventSummary['latest_event_at'] = $latestEvent?->created_at;

        $recentEvents = (clone $eventQuery)
            ->with('device:id,name')
            ->latest('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'summary' => $summary,
            'event_summary' => $eventSummary,
            'devices' => $devices,
            'recent_events' => $recentEvents,
        ]);
    }
}

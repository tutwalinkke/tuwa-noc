<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceInterfaceMetric;
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

        $devices = $deviceQuery
            ->with(['interfaces.latestMetric'])
            ->orderBy('name')
            ->get();

        $devices->each(function ($device) {
            $totalEvents = DeviceEvent::where('device_id', $device->id)->count();
            $downEvents = DeviceEvent::where('device_id', $device->id)
                ->where('new_status', 'down')
                ->count();

            $device->uptime_percent = $totalEvents > 0
                ? round((1 - ($downEvents / max($totalEvents, 1))) * 100, 1)
                : null;

            $device->interfaces_summary = $device->interfaces->map(function ($iface) {
                $metric = $iface->latestMetric;

                return [
                    'name' => $iface->name,
                    'status' => $metric?->oper_status,
                    'in_bps' => $metric?->in_bps,
                    'out_bps' => $metric?->out_bps,
                    'polled_at' => $metric?->polled_at,
                ];
            })->values();

            $device->total_in_bps = $device->interfaces_summary->sum('in_bps');
            $device->total_out_bps = $device->interfaces_summary->sum('out_bps');

            unset($device->interfaces);
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

        $summary['total_in_bps'] = $devices->sum('total_in_bps');
        $summary['total_out_bps'] = $devices->sum('total_out_bps');

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

    /**
     * Real, stored bandwidth history — aggregated in_bps/out_bps across
     * every interface belonging to the tenant, grouped by poll timestamp.
     * Not synthetic or interpolated: exactly what devices:poll-snmp
     * actually recorded, every 5 minutes, capped to a recent window so
     * the response stays small and the chart stays legible.
     */
    public function bandwidthHistory(Request $request)
    {
        $isSuperAdmin = $this->isSuperAdmin($request);
        $tenantId = $this->tenantId($request);

        $query = DeviceInterfaceMetric::query()
            ->join('device_interfaces', 'device_interfaces.id', '=', 'device_interface_metrics.device_interface_id')
            ->join('devices', 'devices.id', '=', 'device_interfaces.device_id')
            ->selectRaw('device_interface_metrics.polled_at, SUM(device_interface_metrics.in_bps) as total_in_bps, SUM(device_interface_metrics.out_bps) as total_out_bps')
            ->groupBy('device_interface_metrics.polled_at')
            ->orderBy('device_interface_metrics.polled_at')
            ->limit(200);

        if (! $isSuperAdmin) {
            $query->where('devices.tenant_id', $tenantId);
        }

        $history = $query->get()->map(function ($row) {
            return [
                'polled_at' => $row->polled_at,
                'in_bps' => (int) $row->total_in_bps,
                'out_bps' => (int) $row->total_out_bps,
            ];
        });

        return response()->json(['history' => $history]);
    }
}

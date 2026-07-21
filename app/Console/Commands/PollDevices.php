<?php

namespace App\Console\Commands;

use App\Models\BillingAccount;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Services\AlertService;
use App\Services\IncidentService;
use Illuminate\Console\Command;

class PollDevices extends Command
{
    protected $signature = 'devices:poll';

    protected $description = 'Ping every device, update its status, log an event on any state change, track it as an incident if actionable, and alert on critical events (unless the device is under a scheduled maintenance window).';

    public function handle(AlertService $alertService, IncidentService $incidentService): int
    {
        $blockedTenantIds = BillingAccount::where('status', 'blocked')->pluck('tenant_id');
        $devices = Device::whereNotIn('tenant_id', $blockedTenantIds)->get();

        if ($blockedTenantIds->isNotEmpty()) {
            $skippedCount = Device::whereIn('tenant_id', $blockedTenantIds)->count();
            $this->comment("Skipping {$skippedCount} device(s) belonging to {$blockedTenantIds->count()} blocked tenant(s).");
        }

        if ($devices->isEmpty()) {
            $this->info('No devices to poll.');
            return self::SUCCESS;
        }

        $this->info("Polling {$devices->count()} device(s)...");

        foreach ($devices as $device) {
            $isUp = $this->ping($device->ip_address);
            $newStatus = $isUp ? 'up' : 'down';
            $previousStatus = $device->status;

            $updates = [
                'status' => $newStatus,
                'last_checked_at' => now(),
            ];

            if ($isUp) {
                $updates['last_seen_up_at'] = now();
            }

            $device->update($updates);

            if ($previousStatus !== $newStatus) {
                $inMaintenance = $device->isInMaintenance();
                $event = $this->logStatusChangeEvent($device, $previousStatus, $newStatus, $inMaintenance);
                $incidentService->maybeCreateFromEvent($event);

                // Still logged either way — a maintenance window explains
                // WHY it went down, it doesn't erase that it happened.
                // Only the alert email is suppressed, since this is
                // expected, not an incident someone needs to be paged for.
                if ($event->severity === 'critical' && ! $inMaintenance) {
                    $alertService->notifyDeviceDown($device, $event->message);
                }
            }

            $maintenanceLabel = $device->isInMaintenance() ? ' <comment>[MAINTENANCE]</comment>' : '';
            $statusLabel = $isUp ? '<info>UP</info>' : '<error>DOWN</error>';
            $this->line("  {$device->name} ({$device->ip_address}) — {$statusLabel}{$maintenanceLabel}");
        }

        $this->info('Polling complete.');
        return self::SUCCESS;
    }

    protected function logStatusChangeEvent(Device $device, ?string $previousStatus, string $newStatus, bool $inMaintenance): DeviceEvent
    {
        if ($newStatus === 'down') {
            if ($inMaintenance) {
                $severity = 'info';
                $message = "{$device->name} went offline during a scheduled maintenance window (expected).";
            } else {
                $severity = 'critical';
                $message = "{$device->name} went offline (no response to ICMP ping).";
            }
        } elseif ($newStatus === 'up' && $previousStatus === 'down') {
            $severity = 'info';
            $message = "{$device->name} recovered and is back online.";
        } else {
            $severity = 'info';
            $message = "{$device->name} status changed to {$newStatus}.";
        }

        return DeviceEvent::create([
            'device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'severity' => $severity,
            'type' => 'status_change',
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    protected function ping(string $ip): bool
    {
        $escapedIp = escapeshellarg($ip);
        exec("ping -c 1 -W 2 {$escapedIp} > /dev/null 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
}

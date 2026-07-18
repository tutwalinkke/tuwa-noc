<?php

namespace App\Console\Commands;

use App\Models\BillingAccount;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Services\AlertService;
use Illuminate\Console\Command;

class PollDevices extends Command
{
    protected $signature = 'devices:poll';

    protected $description = 'Ping every device, update its status, log an event on any state change, and alert on critical events.';

    public function handle(AlertService $alertService): int
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
                $event = $this->logStatusChangeEvent($device, $previousStatus, $newStatus);

                if ($event->severity === 'critical') {
                    $alertService->notifyDeviceDown($device, $event->message);
                }
            }

            $statusLabel = $isUp ? '<info>UP</info>' : '<error>DOWN</error>';
            $this->line("  {$device->name} ({$device->ip_address}) — {$statusLabel}");
        }

        $this->info('Polling complete.');
        return self::SUCCESS;
    }

    protected function logStatusChangeEvent(Device $device, ?string $previousStatus, string $newStatus): DeviceEvent
    {
        if ($newStatus === 'down') {
            $severity = 'critical';
            $message = "{$device->name} went offline (no response to ICMP ping).";
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

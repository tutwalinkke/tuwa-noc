<?php

namespace App\Services;

use App\Mail\BandwidthThresholdAlert;
use App\Mail\DeviceDownAlert;
use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    public function notifyDeviceDown(Device $device, string $message): void
    {
        $recipients = $this->getResponsibleUsers($device->tenant_id);

        if (empty($recipients)) {
            Log::warning("No tenant-admin/super-admin users found for tenant {$device->tenant_id} — alert not sent for device {$device->id}.");
            return;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->queue(new DeviceDownAlert($device, $message));
        }
    }

    public function notifyBandwidthThreshold(Device $device, string $direction, int $currentBps, int $thresholdBps): void
    {
        $recipients = $this->getResponsibleUsers($device->tenant_id);

        if (empty($recipients)) {
            Log::warning("No tenant-admin/super-admin users found for tenant {$device->tenant_id} — bandwidth alert not sent for device {$device->id}.");
            return;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->queue(new BandwidthThresholdAlert($device, $direction, $currentBps, $thresholdBps));
        }
    }

    protected function getResponsibleUsers(int $tenantId): array
    {
        $identityUrl = config('services.identity.url');
        $serviceToken = config('services.identity.service_token');

        $response = Http::withToken($serviceToken)
            ->timeout(5)
            ->get($identityUrl . '/users', ['tenant_id' => $tenantId]);

        if (! $response->successful()) {
            Log::error("Failed to fetch users from Identity for tenant {$tenantId}: " . $response->status());
            return [];
        }

        $users = $response->json('users', []);

        return collect($users)
            ->filter(function ($user) {
                $roleNames = collect($user['roles'] ?? [])->pluck('name');
                return $roleNames->contains('tenant-admin') || $roleNames->contains('super-admin');
            })
            ->filter(fn ($user) => $user['status'] === 'active')
            ->values()
            ->all();
    }
}

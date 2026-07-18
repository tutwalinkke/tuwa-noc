<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class PollDevices extends Command
{
    protected $signature = 'devices:poll';

    protected $description = 'Ping every device and update its status, last_checked_at, and last_seen_up_at.';

    public function handle(): int
    {
        $devices = Device::all();

        if ($devices->isEmpty()) {
            $this->info('No devices to poll.');
            return self::SUCCESS;
        }

        $this->info("Polling {$devices->count()} device(s)...");

        foreach ($devices as $device) {
            $isUp = $this->ping($device->ip_address);

            $updates = [
                'status' => $isUp ? 'up' : 'down',
                'last_checked_at' => now(),
            ];

            if ($isUp) {
                $updates['last_seen_up_at'] = now();
            }

            $device->update($updates);

            $statusLabel = $isUp ? '<info>UP</info>' : '<error>DOWN</error>';
            $this->line("  {$device->name} ({$device->ip_address}) — {$statusLabel}");
        }

        $this->info('Polling complete.');
        return self::SUCCESS;
    }

    /**
     * Ping a host using the system's ping binary.
     * -c 1: send exactly 1 packet
     * -W 2: wait max 2 seconds for a reply
     * Returns true if the host responded, false otherwise.
     */
    protected function ping(string $ip): bool
    {
        $escapedIp = escapeshellarg($ip);
        exec("ping -c 1 -W 2 {$escapedIp} > /dev/null 2>&1", $output, $exitCode);

        return $exitCode === 0;
    }
}

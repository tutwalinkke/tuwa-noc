<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Log;

class WireGuardService
{
    const INTERFACE = 'wg0';
    const SUBNET_PREFIX = '10.20.0.';
    const SUBNET_CIDR_SUFFIX = '/32';

    /**
     * Finds the next unused IP in our WireGuard subnet. Checked
     * against every IP already assigned to an existing device — not
     * against `wg show` directly, since that only reflects peers
     * currently added to the live interface, and we want this to stay
     * consistent even if the interface were ever rebuilt from our own
     * records rather than the other way around.
     */
    public function nextAvailableIp(): ?string
    {
        $usedIps = Device::whereNotNull('wireguard_ip')
            ->pluck('wireguard_ip')
            ->map(fn ($ip) => (int) str_replace(self::SUBNET_PREFIX, '', $ip))
            ->all();

        // .1 is the server itself, .255 is broadcast — usable range is .2-.254.
        for ($i = 2; $i <= 254; $i++) {
            if (! in_array($i, $usedIps, true)) {
                return self::SUBNET_PREFIX . $i;
            }
        }

        return null;
    }

    /**
     * Adds a real peer to the live WireGuard interface. Runs the
     * actual `wg set` command — this is the one part of this service
     * that genuinely cannot be unit-tested without root and a real
     * interface, so it's kept as a thin, isolated wrapper around a
     * single shell call, with everything around it (IP allocation,
     * code validation, device creation) kept in plain, testable PHP.
     */
    public function addPeer(string $publicKey, string $assignedIp): bool
    {
        $escapedInterface = escapeshellarg(self::INTERFACE);
        $escapedKey = escapeshellarg($publicKey);
        $escapedIp = escapeshellarg($assignedIp . self::SUBNET_CIDR_SUFFIX);

        $command = "wg set {$escapedInterface} peer {$escapedKey} allowed-ips {$escapedIp}";
        exec("sudo {$command} 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            Log::error('Failed to add WireGuard peer: ' . implode("\n", $output));
            return false;
        }

        // Persist the running config to the on-disk conf file too, so
        // the peer survives a server reboot — `wg set` alone only
        // affects the live in-kernel state.
        exec('sudo wg-quick save ' . escapeshellarg(self::INTERFACE) . ' 2>&1', $saveOutput, $saveExitCode);
        if ($saveExitCode !== 0) {
            Log::warning('wg-quick save failed after adding peer (peer is live but will not survive a reboot): ' . implode("\n", $saveOutput));
        }

        return true;
    }

    public function removePeer(string $publicKey): bool
    {
        $escapedInterface = escapeshellarg(self::INTERFACE);
        $escapedKey = escapeshellarg($publicKey);

        exec("sudo wg set {$escapedInterface} peer {$escapedKey} remove 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            Log::error('Failed to remove WireGuard peer: ' . implode("\n", $output));
            return false;
        }

        return true;
    }

    public function serverPublicKey(): string
    {
        return config('services.wireguard.server_public_key');
    }

    public function serverEndpoint(): string
    {
        return config('services.wireguard.endpoint', '129.121.102.51:51821');
    }
}

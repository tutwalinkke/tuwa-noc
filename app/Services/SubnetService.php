<?php

namespace App\Services;

use App\Models\IpAddress;
use App\Models\Subnet;
use InvalidArgumentException;

class SubnetService
{
    /**
     * Parse a CIDR block (e.g. "10.0.0.0/29") and return every usable
     * host address as a plain string array. Network and broadcast
     * addresses are excluded, matching standard IPv4 subnetting
     * conventions — those two addresses are never individually
     * assignable to a device.
     */
    public function generateUsableAddresses(string $cidr): array
    {
        if (! str_contains($cidr, '/')) {
            throw new InvalidArgumentException("Invalid CIDR notation: {$cidr}");
        }

        [$network, $prefixLength] = explode('/', $cidr);
        $prefixLength = (int) $prefixLength;

        if ($prefixLength < 0 || $prefixLength > 32) {
            throw new InvalidArgumentException("Invalid prefix length: /{$prefixLength}");
        }

        $networkLong = ip2long($network);
        if ($networkLong === false) {
            throw new InvalidArgumentException("Invalid network address: {$network}");
        }

        $hostBits = 32 - $prefixLength;
        $totalAddresses = 2 ** $hostBits;

        // Guard against accidentally generating an enormous subnet
        // (e.g. /8) that would create millions of rows and lock up
        // the database — anything smaller than a /22 (1024 addresses)
        // is refused outright rather than silently taking forever.
        if ($totalAddresses > 1024) {
            throw new InvalidArgumentException(
                "Subnet too large to generate individually (/{$prefixLength} = {$totalAddresses} addresses). Maximum supported is /22."
            );
        }

        // /31 and /32 are special cases with no usable host range in
        // the traditional sense (point-to-point links, single hosts) —
        // return every address in the block rather than excluding any.
        if ($prefixLength >= 31) {
            $addresses = [];
            for ($i = 0; $i < $totalAddresses; $i++) {
                $addresses[] = long2ip($networkLong + $i);
            }
            return $addresses;
        }

        $broadcastLong = $networkLong + $totalAddresses - 1;

        $addresses = [];
        for ($i = $networkLong + 1; $i < $broadcastLong; $i++) {
            $addresses[] = long2ip($i);
        }

        return $addresses;
    }

    /**
     * Create a subnet and populate it with 'available' IpAddress rows
     * for every usable host address in the block.
     */
    public function createSubnetWithAddresses(int $tenantId, string $cidr, ?string $description, ?string $site, ?string $vlanId): Subnet
    {
        $addresses = $this->generateUsableAddresses($cidr);

        $subnet = Subnet::create([
            'tenant_id' => $tenantId,
            'cidr' => $cidr,
            'description' => $description,
            'site' => $site,
            'vlan_id' => $vlanId,
        ]);

        $now = now();
        $rows = array_map(fn ($address) => [
            'subnet_id' => $subnet->id,
            'tenant_id' => $tenantId,
            'address' => $address,
            'status' => 'available',
            'device_id' => null,
            'label' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $addresses);

        // Bulk insert rather than individual Eloquent creates — much
        // faster for subnets with hundreds of addresses, and this is
        // a one-time generation step, not something needing model events.
        IpAddress::insert($rows);

        return $subnet;
    }

    /**
     * Find and allocate the next available IP in a subnet, atomically —
     * uses a row lock to prevent two simultaneous requests from both
     * grabbing the same "available" address in a race condition.
     */
    public function allocateNextAvailable(Subnet $subnet, ?int $deviceId, ?string $label): ?IpAddress
    {
        return \DB::transaction(function () use ($subnet, $deviceId, $label) {
            $ip = IpAddress::where('subnet_id', $subnet->id)
                ->where('status', 'available')
                ->lockForUpdate()
                ->orderBy('address')
                ->first();

            if (! $ip) {
                return null;
            }

            $ip->update([
                'status' => 'allocated',
                'device_id' => $deviceId,
                'label' => $label,
            ]);

            return $ip;
        });
    }
}

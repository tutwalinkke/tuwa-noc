<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceInterfaceMetric;
use Illuminate\Console\Command;

class PollDeviceInterfaces extends Command
{
    protected $signature = 'devices:poll-snmp';

    protected $description = 'Poll SNMP interface stats (status, byte counters, computed throughput) for devices with a community string configured.';

    const OID_IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    const OID_IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';
    const OID_IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';
    const OID_IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';

    const OPER_STATUS_MAP = [
        1 => 'up',
        2 => 'down',
        3 => 'testing',
        4 => 'unknown',
        5 => 'dormant',
        6 => 'notPresent',
        7 => 'lowerLayerDown',
    ];

    public function handle(): int
    {
        $devices = Device::whereNotNull('snmp_community')->get();

        if ($devices->isEmpty()) {
            $this->info('No devices with SNMP configured.');
            return self::SUCCESS;
        }

        $this->info("Polling SNMP for {$devices->count()} device(s)...");

        foreach ($devices as $device) {
            $this->pollDevice($device);
        }

        $this->info('SNMP polling complete.');
        return self::SUCCESS;
    }

    protected function cleanSnmpValue(string $raw): string
    {
        if (preg_match('/STRING:\s*"(.*)"/', $raw, $m)) {
            return $m[1];
        }

        if (preg_match('/(?:INTEGER|Counter32|Counter64|Gauge32|Timeticks):\s*(-?\d+)/', $raw, $m)) {
            return $m[1];
        }

        $cleaned = preg_replace('/^[A-Za-z0-9]+:\s*/', '', $raw);
        return trim($cleaned, "\" \t\n\r\0\x0B");
    }

    protected function pollDevice(Device $device): void
    {
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);

        $ip = $device->ip_address;
        $community = $device->snmp_community;

        $descrRaw = @snmp2_real_walk($ip, $community, self::OID_IF_DESCR, 3000000, 1);
        $statusRaw = @snmp2_real_walk($ip, $community, self::OID_IF_OPER_STATUS, 3000000, 1);
        $inOctetsRaw = @snmp2_real_walk($ip, $community, self::OID_IF_IN_OCTETS, 3000000, 1);
        $outOctetsRaw = @snmp2_real_walk($ip, $community, self::OID_IF_OUT_OCTETS, 3000000, 1);

        if ($descrRaw === false) {
            $this->line("  {$device->name} ({$ip}) — <error>SNMP UNREACHABLE</error>");
            return;
        }

        $now = now();

        foreach ($descrRaw as $oid => $descrValue) {
            $ifIndex = (int) substr($oid, strrpos($oid, '.') + 1);
            $ifName = $this->cleanSnmpValue($descrValue);

            $statusOid = '.' . self::OID_IF_OPER_STATUS . '.' . $ifIndex;
            $inOid = '.' . self::OID_IF_IN_OCTETS . '.' . $ifIndex;
            $outOid = '.' . self::OID_IF_OUT_OCTETS . '.' . $ifIndex;

            $statusCode = isset($statusRaw[$statusOid])
                ? (int) $this->cleanSnmpValue($statusRaw[$statusOid])
                : null;
            $operStatus = self::OPER_STATUS_MAP[$statusCode] ?? 'unknown';

            $inOctets = isset($inOctetsRaw[$inOid])
                ? (int) $this->cleanSnmpValue($inOctetsRaw[$inOid])
                : null;
            $outOctets = isset($outOctetsRaw[$outOid])
                ? (int) $this->cleanSnmpValue($outOctetsRaw[$outOid])
                : null;

            $interface = DeviceInterface::updateOrCreate(
                ['device_id' => $device->id, 'if_index' => $ifIndex],
                ['tenant_id' => $device->tenant_id, 'name' => $ifName]
            );

            $previous = $interface->latestMetric;

            [$inBps, $outBps] = $this->computeRates($previous, $inOctets, $outOctets, $now);

            DeviceInterfaceMetric::create([
                'device_interface_id' => $interface->id,
                'tenant_id' => $device->tenant_id,
                'oper_status' => $operStatus,
                'in_octets' => $inOctets,
                'out_octets' => $outOctets,
                'in_bps' => $inBps,
                'out_bps' => $outBps,
                'polled_at' => $now,
            ]);

            $inBpsLabel = $inBps ?? 'n/a';
            $outBpsLabel = $outBps ?? 'n/a';
            $this->line("  {$device->name} / {$ifName} — {$operStatus}, in={$inBpsLabel}bps out={$outBpsLabel}bps");
        }
    }

    protected function computeRates(?DeviceInterfaceMetric $previous, ?int $inOctets, ?int $outOctets, $now): array
    {
        if (! $previous || $inOctets === null || $outOctets === null || $previous->in_octets === null) {
            return [null, null];
        }

        $secondsElapsed = abs($now->diffInSeconds($previous->polled_at));

        if ($secondsElapsed <= 0) {
            return [null, null];
        }

        if ($inOctets < $previous->in_octets || $outOctets < $previous->out_octets) {
            return [null, null];
        }

        $inBps = (int) round((($inOctets - $previous->in_octets) * 8) / $secondsElapsed);
        $outBps = (int) round((($outOctets - $previous->out_octets) * 8) / $secondsElapsed);

        return [$inBps, $outBps];
    }
}

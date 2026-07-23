<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceProvisioningCode;
use App\Services\ActivityLogger;
use App\Services\WireGuardService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceProvisioningCodeController extends Controller
{
    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    /**
     * Generates a fresh, single-use provisioning code — this is a
     * normal authenticated Portal action (the person clicking "Add
     * Device" already has a real session). Short expiry (15 minutes)
     * limits how long a leaked/screenshotted code stays exploitable.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_type' => 'nullable|in:router,switch,olt,server,ups,access_point,other',
            'device_name' => 'nullable|string|max:255',
        ]);

        $code = DeviceProvisioningCode::create([
            'code' => Str::random(40),
            'tenant_id' => $this->tenantId($request),
            'device_type' => $validated['device_type'] ?? 'router',
            'device_name' => $validated['device_name'] ?? null,
            'created_by_user_id' => $request->attributes->get('identity_user')['id'] ?? null,
            'expires_at' => now()->addMinutes(15),
        ]);

        ActivityLogger::log($request, 'Provisioning code generated', ['type' => 'DeviceProvisioningCode', 'id' => $code->id]);

        return response()->json([
            'code' => $code->code,
            'expires_at' => $code->expires_at,
        ], 201);
    }

    /**
     * The redemption endpoint — deliberately NOT behind identity.auth,
     * since a fresh router has no bearer token yet. The code itself
     * IS the credential here, which is exactly why it must be
     * single-use, short-lived, and rate-limited (see routes/api.php).
     * A leaked code is a real risk for 15 minutes, not indefinitely.
     */
    /**
     * Public, code-authenticated status check — same trust model as
     * redeem() itself, since this just tells the Portal (which
     * already generated and displayed the code) whether provisioning
     * has completed and the device is reachable. Deliberately not a
     * generic device-lookup endpoint; it only ever answers "for THIS
     * code, what happened."
     */
    public function status(Request $request, string $code)
    {
        $provisioningCode = DeviceProvisioningCode::where('code', $code)->first();

        if (! $provisioningCode) {
            return response()->json(['message' => 'Invalid provisioning code.'], 404);
        }

        if (! $provisioningCode->isUsed()) {
            return response()->json(['status' => 'waiting_for_redemption']);
        }

        $device = $provisioningCode->device;

        if (! $device) {
            // Genuinely shouldn't happen — used_at is only ever set
            // alongside device_id in redeem() — but fail informatively
            // rather than crash if it somehow does.
            return response()->json(['status' => 'redeemed_no_device']);
        }

        return response()->json([
            'status' => $device->status === 'up' ? 'connected' : 'waiting_for_connection',
            'device_id' => $device->id,
            'device_name' => $device->name,
            'device_status' => $device->status,
        ]);
    }

    public function redeem(Request $request, WireGuardService $wireGuard)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'wireguard_public_key' => 'required|string|size:44',
            'device_name' => 'nullable|string|max:255',
        ]);

        $provisioningCode = DeviceProvisioningCode::where('code', $validated['code'])->first();

        if (! $provisioningCode) {
            return response()->json(['message' => 'Invalid provisioning code.'], 404);
        }

        if (! $provisioningCode->isRedeemable()) {
            $reason = $provisioningCode->isUsed() ? 'already been used' : 'expired';
            return response()->json(['message' => "This provisioning code has {$reason}."], 422);
        }

        $assignedIp = $wireGuard->nextAvailableIp();
        if ($assignedIp === null) {
            return response()->json(['message' => 'No available WireGuard IPs remain. Contact support.'], 503);
        }

        $peerAdded = $wireGuard->addPeer($validated['wireguard_public_key'], $assignedIp);
        if (! $peerAdded) {
            return response()->json(['message' => 'Could not establish the WireGuard tunnel. Please try again.'], 500);
        }

        // Randomly generated per device — deliberately never a
        // shared community string across the fleet, since that would
        // mean a single leaked/guessed community grants read access
        // to every device, not just one. Combined with SNMP being
        // configured (by the RouterOS script) to only accept
        // connections from our own tunnel IP, not the WAN/LAN, this
        // is a real, meaningfully scoped credential per device.
        $snmpCommunity = Str::random(24);

        // The name chosen up front in the Portal (when the code was
        // generated) takes priority over whatever the router itself
        // sends — the person provisioning it is the authoritative
        // source, not an arbitrary field a router happens to include.
        $device = Device::create([
            'tenant_id' => $provisioningCode->tenant_id,
            'name' => $provisioningCode->device_name ?? $validated['device_name'] ?? 'Provisioned Device',
            'ip_address' => $assignedIp,
            'type' => $provisioningCode->device_type,
            'status' => 'unknown',
            'wireguard_ip' => $assignedIp,
            'wireguard_public_key' => $validated['wireguard_public_key'],
            'snmp_community' => $snmpCommunity,
        ]);

        $provisioningCode->update([
            'device_id' => $device->id,
            'wireguard_public_key' => $validated['wireguard_public_key'],
            'assigned_wg_ip' => $assignedIp,
            'used_at' => now(),
        ]);

        // JSON_UNESCAPED_SLASHES matters here specifically: WireGuard
        // base64 keys routinely contain '/' characters, and a RouterOS
        // script parsing this response has to do its own string
        // manipulation to extract fields — asking it to also handle
        // Laravel's default '\/' escaping is unnecessary complexity
        // this endpoint can simply not create for every future client.
        return response()->json([
            'device_id' => $device->id,
            'assigned_ip' => $assignedIp,
            'server_public_key' => $wireGuard->serverPublicKey(),
            'server_endpoint' => $wireGuard->serverEndpoint(),
            'snmp_community' => $snmpCommunity,
            'server_wireguard_ip' => '10.20.0.1',
        ], 201, [], JSON_UNESCAPED_SLASHES);
    }
}

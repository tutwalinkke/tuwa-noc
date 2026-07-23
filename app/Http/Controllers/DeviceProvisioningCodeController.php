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
        ]);

        $code = DeviceProvisioningCode::create([
            'code' => Str::random(40),
            'tenant_id' => $this->tenantId($request),
            'device_type' => $validated['device_type'] ?? 'router',
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

        $device = Device::create([
            'tenant_id' => $provisioningCode->tenant_id,
            'name' => $validated['device_name'] ?? 'Provisioned Device',
            'ip_address' => $assignedIp,
            'type' => $provisioningCode->device_type,
            'status' => 'unknown',
            'wireguard_ip' => $assignedIp,
            'wireguard_public_key' => $validated['wireguard_public_key'],
        ]);

        $provisioningCode->update([
            'device_id' => $device->id,
            'wireguard_public_key' => $validated['wireguard_public_key'],
            'assigned_wg_ip' => $assignedIp,
            'used_at' => now(),
        ]);

        return response()->json([
            'device_id' => $device->id,
            'assigned_ip' => $assignedIp,
            'server_public_key' => $wireGuard->serverPublicKey(),
            'server_endpoint' => $wireGuard->serverEndpoint(),
        ], 201);
    }
}

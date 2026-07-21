<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    protected function resolveIncident(Request $request, int $id): Incident
    {
        $incident = Incident::findOrFail($id);

        if (! $this->isSuperAdmin($request) && $incident->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return $incident;
    }

    public function index(Request $request)
    {
        $query = Incident::query()->with(['device:id,name,ip_address', 'deviceEvent']);

        if (! $this->isSuperAdmin($request)) {
            $query->where('tenant_id', $this->tenantId($request));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'incidents' => $query->orderByDesc('created_at')->limit(100)->get(),
        ]);
    }

    public function acknowledge(Request $request, int $id)
    {
        $incident = $this->resolveIncident($request, $id);

        if (! $incident->isOpen()) {
            return response()->json(['message' => 'This incident is not open.'], 422);
        }

        $incident->update([
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $request->attributes->get('identity_user')['id'] ?? null,
            'acknowledged_at' => now(),
        ]);

        ActivityLogger::log(
            $request,
            "Incident #{$incident->id} acknowledged for {$incident->device->name}",
            ['type' => 'Incident', 'id' => $incident->id]
        );

        return response()->json(['incident' => $incident->fresh()->load('device:id,name,ip_address')]);
    }

    public function resolve(Request $request, int $id)
    {
        $incident = $this->resolveIncident($request, $id);

        if ($incident->status === 'resolved') {
            return response()->json(['message' => 'This incident is already resolved.'], 422);
        }

        $incident->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        ActivityLogger::log(
            $request,
            "Incident #{$incident->id} resolved for {$incident->device->name}",
            ['type' => 'Incident', 'id' => $incident->id]
        );

        return response()->json(['incident' => $incident->fresh()->load('device:id,name,ip_address')]);
    }
}

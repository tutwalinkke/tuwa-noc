<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('identity_user')['tenant_id'];
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return in_array('super-admin', $request->attributes->get('identity_roles', []), true);
    }

    public function index(Request $request)
    {
        $isSuperAdmin = $this->isSuperAdmin($request);
        $tenantId = $this->tenantId($request);

        // Filtering by properties->tenant_id in PHP rather than via a
        // database-level JSON query — whereJsonContains and the ->
        // shorthand both have real, confirmed behavior differences
        // between MySQL and SQLite for scalar values nested in a JSON
        // object, which made tenant isolation unreliable across
        // environments. A plain PHP filter is slower per-row but is
        // driver-agnostic and, given this endpoint is already capped,
        // the correctness guarantee is worth more than the query-level
        // optimization here.
        $activities = Activity::query()
            ->latest('created_at')
            ->limit(500)
            ->get()
            ->filter(function ($activity) use ($isSuperAdmin, $tenantId) {
                if ($isSuperAdmin) {
                    return true;
                }
                return ($activity->properties['tenant_id'] ?? null) === $tenantId;
            })
            ->take(100)
            ->values()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'actor_name' => $activity->properties['actor_name'] ?? null,
                    'actor_email' => $activity->properties['actor_email'] ?? null,
                    'created_at' => $activity->created_at,
                ];
            });

        return response()->json(['activities' => $activities]);
    }
}

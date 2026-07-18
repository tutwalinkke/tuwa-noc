<?php

namespace App\Services;

use Illuminate\Http\Request;
use Spatie\Activitylog\Facades\LogBatch;
use Spatie\Activitylog\Models\Activity;

class ActivityLogger
{
    /**
     * Log an activity attributed to the currently authenticated identity
     * user (from identity.auth middleware), not a local Eloquent model —
     * NOC deliberately has no local users table. Actor identity is stored
     * in the properties JSON field, and tenant_id there is what powers
     * tenant-scoped filtering when reading the log back.
     */
    public static function log(Request $request, string $description, ?array $subject = null): Activity
    {
        $identityUser = $request->attributes->get('identity_user', []);

        $properties = [
            'actor_id' => $identityUser['id'] ?? null,
            'actor_name' => $identityUser['name'] ?? null,
            'actor_email' => $identityUser['email'] ?? null,
            'tenant_id' => $identityUser['tenant_id'] ?? null,
        ];

        if ($subject) {
            $properties['subject_type'] = $subject['type'] ?? null;
            $properties['subject_id'] = $subject['id'] ?? null;
        }

        return activity()
            ->withProperties($properties)
            ->log($description);
    }
}

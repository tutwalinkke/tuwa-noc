<?php

namespace App\Services;

use App\Models\DeviceEvent;
use App\Models\Incident;

class IncidentService
{
    /**
     * Creates an Incident for a DeviceEvent, but only if it's genuinely
     * actionable (critical or warning severity) — routine info events
     * (recoveries, maintenance-suppressed events) don't need a tracked
     * incident. This is also why maintenance windows don't need any
     * separate handling here: they already downgrade severity to info
     * upstream, so incident creation is naturally skipped for them too.
     */
    public function maybeCreateFromEvent(DeviceEvent $event): ?Incident
    {
        if (! in_array($event->severity, ['critical', 'warning'], true)) {
            return null;
        }

        return Incident::create([
            'device_event_id' => $event->id,
            'device_id' => $event->device_id,
            'tenant_id' => $event->tenant_id,
            'status' => 'open',
        ]);
    }
}

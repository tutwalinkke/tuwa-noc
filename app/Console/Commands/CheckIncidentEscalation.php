<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Services\AlertService;
use Illuminate\Console\Command;

class CheckIncidentEscalation extends Command
{
    protected $signature = 'incidents:check-escalation {--minutes=30 : How long an incident can stay open/unacknowledged before escalating}';

    protected $description = 'Escalate incidents that have been open (unacknowledged) longer than the configured threshold.';

    public function handle(AlertService $alertService): int
    {
        $thresholdMinutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($thresholdMinutes);

        // Only genuinely open incidents that haven't already been
        // escalated once — escalated_at being set means this already
        // fired, and firing repeatedly every run would just be noise
        // on top of the original alert and the escalation itself.
        $incidents = Incident::where('status', 'open')
            ->whereNull('escalated_at')
            ->where('created_at', '<=', $cutoff)
            ->with(['device', 'deviceEvent'])
            ->get();

        if ($incidents->isEmpty()) {
            $this->info('No incidents require escalation.');
            return self::SUCCESS;
        }

        $this->info("Escalating {$incidents->count()} incident(s)...");

        foreach ($incidents as $incident) {
            $minutesOpen = (int) $incident->created_at->diffInMinutes(now());

            $alertService->notifyIncidentEscalation($incident, $minutesOpen);
            $incident->update(['escalated_at' => now()]);

            $this->line("  <comment>Incident #{$incident->id} ({$incident->device->name}) escalated after {$minutesOpen} minutes unacknowledged.</comment>");
        }

        $this->info('Escalation check complete.');
        return self::SUCCESS;
    }
}

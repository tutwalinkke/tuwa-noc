<?php

namespace Tests\Feature;

use App\Mail\IncidentEscalationAlert;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Incident;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], string $token = 'fake-test-token'): string
    {
        Http::fake([
            '*/api/v1/me' => Http::response([
                'user' => ['id' => 1, 'tenant_id' => $tenantId, 'name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active'],
                'roles' => $roles,
                'permissions' => [],
            ], 200),
        ]);

        return $token;
    }

    protected function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    protected function fakeIdentityUsers(int $tenantId): void
    {
        Http::fake([
            '*/api/v1/users*' => Http::response([
                'users' => [
                    [
                        'id' => 1,
                        'tenant_id' => $tenantId,
                        'email' => 'admin@example.com',
                        'status' => 'active',
                        'roles' => [['name' => 'super-admin']],
                    ],
                ],
            ], 200),
        ]);
    }

    protected function makeCriticalEvent(int $tenantId = 1): DeviceEvent
    {
        $device = Device::create([
            'tenant_id' => $tenantId, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'down',
        ]);

        return DeviceEvent::create([
            'device_id' => $device->id,
            'tenant_id' => $tenantId,
            'severity' => 'critical',
            'type' => 'status_change',
            'previous_status' => 'up',
            'new_status' => 'down',
            'message' => "{$device->name} went offline.",
            'created_at' => now(),
        ]);
    }

    // --- IncidentService ---

    public function test_critical_event_creates_an_open_incident(): void
    {
        $event = $this->makeCriticalEvent();

        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);

        $this->assertNotNull($incident);
        $this->assertSame('open', $incident->status);
        $this->assertSame($event->id, $incident->device_event_id);
    }

    public function test_info_event_does_not_create_an_incident(): void
    {
        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $event = DeviceEvent::create([
            'device_id' => $device->id,
            'tenant_id' => 1,
            'severity' => 'info',
            'type' => 'status_change',
            'previous_status' => 'down',
            'new_status' => 'up',
            'message' => 'Recovered.',
            'created_at' => now(),
        ]);

        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);

        $this->assertNull($incident);
        $this->assertSame(0, Incident::count());
    }

    // --- API ---

    public function test_can_list_incidents(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $event = $this->makeCriticalEvent(tenantId: 1);
        app(IncidentService::class)->maybeCreateFromEvent($event);

        $response = $this->getJson('/api/v1/incidents', $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('incidents'));
    }

    public function test_incidents_are_tenant_scoped(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $ownEvent = $this->makeCriticalEvent(tenantId: 1);
        $otherEvent = $this->makeCriticalEvent(tenantId: 2);
        app(IncidentService::class)->maybeCreateFromEvent($ownEvent);
        app(IncidentService::class)->maybeCreateFromEvent($otherEvent);

        $response = $this->getJson('/api/v1/incidents', $this->authHeader($token));

        $this->assertCount(1, $response->json('incidents'));
    }

    public function test_can_acknowledge_an_open_incident(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge", [], $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertSame('acknowledged', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->acknowledged_at);
    }

    public function test_cannot_acknowledge_an_already_acknowledged_incident(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);
        $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge", [], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_can_resolve_an_incident(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/resolve", [], $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    // --- Escalation command ---

    public function test_escalates_incident_open_longer_than_threshold(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        Carbon::setTestNow(now()->subMinutes(45));
        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);
        Carbon::setTestNow();

        $this->artisan('incidents:check-escalation', ['--minutes' => 30])->assertExitCode(0);

        Mail::assertQueued(IncidentEscalationAlert::class);
        $this->assertNotNull($incident->fresh()->escalated_at);
    }

    public function test_does_not_escalate_incident_still_within_threshold(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);

        $this->artisan('incidents:check-escalation', ['--minutes' => 30])->assertExitCode(0);

        Mail::assertNotQueued(IncidentEscalationAlert::class);
        $this->assertNull($incident->fresh()->escalated_at);
    }

    public function test_does_not_escalate_an_acknowledged_incident(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        Carbon::setTestNow(now()->subMinutes(45));
        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);
        Carbon::setTestNow();

        $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        $this->artisan('incidents:check-escalation', ['--minutes' => 30])->assertExitCode(0);

        Mail::assertNotQueued(IncidentEscalationAlert::class);
    }

    public function test_does_not_escalate_the_same_incident_twice(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        Carbon::setTestNow(now()->subMinutes(45));
        $event = $this->makeCriticalEvent(tenantId: 1);
        $incident = app(IncidentService::class)->maybeCreateFromEvent($event);
        Carbon::setTestNow();

        $this->artisan('incidents:check-escalation', ['--minutes' => 30]);
        $this->artisan('incidents:check-escalation', ['--minutes' => 30]);

        Mail::assertQueued(IncidentEscalationAlert::class, 1);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');

            // open -> acknowledged -> resolved. Deliberately not
            // resurrecting an incident automatically if the same
            // condition recurs later — a fresh DeviceEvent creates a
            // fresh Incident, keeping the history honest rather than
            // reusing/reopening an old record.
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');

            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Set once escalation actually fires, so the escalation
            // checker never sends a second escalation for the same
            // incident on every subsequent run.
            $table->timestamp('escalated_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'escalated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};

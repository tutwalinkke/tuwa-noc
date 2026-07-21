<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_links', function (Blueprint $table) {
            $table->id();

            // Manually declared, not auto-discovered — real LLDP/CDP
            // discovery needs actual protocol access to real hardware,
            // which isn't available in this environment. This is an
            // operator-maintained record of how devices connect.
            $table->foreignId('device_a_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('device_b_id')->constrained('devices')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');

            $table->string('link_type')->nullable(); // fiber, ethernet, wireless, etc.
            $table->string('description')->nullable();

            $table->timestamps();

            // Application-level logic (not this constraint alone) is
            // responsible for always storing the lower device ID as
            // device_a_id, so this genuinely prevents a duplicate link
            // being declared in the reverse direction too.
            $table->unique(['device_a_id', 'device_b_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_links');
    }
};

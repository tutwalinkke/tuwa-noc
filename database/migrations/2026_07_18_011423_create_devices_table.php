<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('ip_address');
            $table->enum('type', ['router', 'switch', 'olt', 'server', 'ups', 'access_point', 'other'])->default('other');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('site')->nullable();
            $table->enum('status', ['unknown', 'up', 'down'])->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_seen_up_at')->nullable();
            $table->timestamps();

            // A tenant shouldn't be able to register the exact same IP twice —
            // catches accidental duplicate entries at the DB level.
            $table->unique(['tenant_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

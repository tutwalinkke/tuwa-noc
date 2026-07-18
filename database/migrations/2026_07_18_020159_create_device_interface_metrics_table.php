<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_interface_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_interface_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('oper_status')->nullable();
            $table->unsignedBigInteger('in_octets')->nullable();
            $table->unsignedBigInteger('out_octets')->nullable();
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();
            $table->timestamp('polled_at');

            $table->index(['device_interface_id', 'polled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_interface_metrics');
    }
};

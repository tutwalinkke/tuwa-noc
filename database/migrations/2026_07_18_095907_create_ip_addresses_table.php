<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subnet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('address');
            $table->enum('status', ['available', 'allocated', 'reserved'])->default('available');
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_provisioning_codes', function (Blueprint $table) {
            $table->id();

            // Short random token, single-use. This is the ONLY
            // credential a fresh, unconfigured router has to
            // authenticate itself with — deliberately not a normal
            // Sanctum bearer token, since the router doesn't have one
            // yet. Short expiry + single-use are what make this safe.
            $table->string('code', 64)->unique();

            $table->unsignedBigInteger('tenant_id');
            $table->string('device_type')->default('router');
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            // Populated at redemption time, not creation time.
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wireguard_public_key')->nullable();
            $table->string('assigned_wg_ip')->nullable();
            $table->timestamp('used_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_provisioning_codes');
    }
};

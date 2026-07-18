<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('cidr');
            $table->string('description')->nullable();
            $table->string('site')->nullable();
            $table->string('vlan_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cidr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subnets');
    }
};

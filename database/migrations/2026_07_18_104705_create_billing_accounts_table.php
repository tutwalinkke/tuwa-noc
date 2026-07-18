<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->timestamp('tenant_created_at');
            $table->timestamp('trial_ends_at');
            $table->timestamp('billing_anchor_date');
            $table->enum('status', ['trial', 'active', 'overdue', 'blocked'])->default('trial');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_accounts');
    }
};

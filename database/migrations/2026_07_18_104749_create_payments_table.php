<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->decimal('amount', 10, 2);
            $table->enum('method', ['mpesa_manual', 'mpesa_stk', 'bank_transfer', 'other'])->default('mpesa_manual');
            $table->string('reference')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

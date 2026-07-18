<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->integer('if_index');
            $table->string('name');
            $table->timestamps();

            $table->unique(['device_id', 'if_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_interfaces');
    }
};

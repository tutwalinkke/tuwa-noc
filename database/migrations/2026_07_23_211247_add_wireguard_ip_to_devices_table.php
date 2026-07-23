<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('wireguard_ip')->nullable()->unique()->after('snmp_community');
            $table->string('wireguard_public_key')->nullable()->after('wireguard_ip');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['wireguard_ip', 'wireguard_public_key']);
        });
    }
};

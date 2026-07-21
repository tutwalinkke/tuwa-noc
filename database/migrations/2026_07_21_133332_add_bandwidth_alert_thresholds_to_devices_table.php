<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Thresholds are per-device totals (summed across all its
            // interfaces), matching how the Dashboard already presents
            // bandwidth — not per-interface, to keep this genuinely
            // simple to configure. Null means no threshold set, no
            // checking happens for that direction.
            $table->unsignedBigInteger('alert_threshold_in_bps')->nullable()->after('snmp_community');
            $table->unsignedBigInteger('alert_threshold_out_bps')->nullable()->after('alert_threshold_in_bps');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['alert_threshold_in_bps', 'alert_threshold_out_bps']);
        });
    }
};

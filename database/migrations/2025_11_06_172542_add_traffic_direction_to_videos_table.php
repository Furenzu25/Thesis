<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Traffic direction for line crossing detection
            // Options: left_to_right, right_to_left, top_to_bottom, bottom_to_top, none
            $table->string('traffic_direction')->default('none')->after('privacy_blur_enabled');
            
            // Store line crossing statistics per class
            // Example: {"motorcycle": 45, "sedan": 23, "bus": 5}
            $table->json('line_crossing_by_class')->nullable()->after('line_crossing_direction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['traffic_direction', 'line_crossing_by_class']);
        });
    }
};

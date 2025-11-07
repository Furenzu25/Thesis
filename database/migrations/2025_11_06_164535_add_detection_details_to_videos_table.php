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
            // Store class-wise detection counts as JSON
            // Example: {"motorcycle": 85, "sedan": 40, "medium_vehicle": 20}
            $table->json('class_counts')->nullable()->after('average_detections_per_frame');
            
            // Store per-minute traffic data as JSON for timeline visualization
            // Example: [{"minute": 0, "count": 15}, {"minute": 1, "count": 22}, ...]
            $table->json('traffic_timeline')->nullable()->after('class_counts');
            
            // Store peak traffic minute and count
            $table->integer('peak_minute')->nullable()->after('traffic_timeline');
            $table->integer('peak_count')->nullable()->after('peak_minute');
            
            // Line crossing count (if applicable)
            $table->integer('line_crossing_count')->nullable()->after('peak_count');
            $table->string('line_crossing_direction')->nullable()->after('line_crossing_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'class_counts',
                'traffic_timeline',
                'peak_minute',
                'peak_count',
                'line_crossing_count',
                'line_crossing_direction',
            ]);
        });
    }
};

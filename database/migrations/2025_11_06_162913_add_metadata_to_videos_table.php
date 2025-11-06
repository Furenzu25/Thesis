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
            // Metadata fields
            $table->string('location_name')->nullable()->after('description');
            $table->string('time_of_day')->nullable()->after('location_name');
            $table->string('weather_condition')->nullable()->after('time_of_day');
            
            // Video properties
            $table->integer('duration_seconds')->nullable()->after('file_size');
            $table->string('resolution')->nullable()->after('duration_seconds');
            $table->string('video_format')->default('mp4')->after('resolution');
            
            // Advanced inference configuration
            $table->decimal('confidence_threshold', 3, 2)->default(0.25)->after('processing_duration');
            $table->boolean('privacy_blur_enabled')->default(false)->after('confidence_threshold');
            
            // Progress tracking
            $table->integer('total_frames')->nullable()->after('privacy_blur_enabled');
            $table->integer('processed_frames')->nullable()->after('total_frames');
            $table->decimal('processing_progress', 5, 2)->default(0)->after('processed_frames'); // 0-100%
            
            // Detection statistics
            $table->integer('total_detections')->default(0)->after('processing_progress');
            $table->decimal('average_detections_per_frame', 8, 2)->default(0)->after('total_detections');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'location_name',
                'time_of_day',
                'weather_condition',
                'duration_seconds',
                'resolution',
                'video_format',
                'confidence_threshold',
                'privacy_blur_enabled',
                'total_frames',
                'processed_frames',
                'processing_progress',
                'total_detections',
                'average_detections_per_frame',
            ]);
        });
    }
};

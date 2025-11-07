<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'location_name',
        'time_of_day',
        'weather_condition',
        'original_filename',
        'original_path',
        'processed_path',
        'file_size',
        'duration_seconds',
        'resolution',
        'video_format',
        'status',
        'error_message',
        'processing_duration',
        'confidence_threshold',
        'privacy_blur_enabled',
        'traffic_direction',
        'total_frames',
        'processed_frames',
        'processing_progress',
        'total_detections',
        'average_detections_per_frame',
        'class_counts',
        'traffic_timeline',
        'peak_minute',
        'peak_count',
        'line_crossing_count',
        'line_crossing_direction',
        'line_crossing_by_class',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'processing_duration' => 'integer',
        'duration_seconds' => 'integer',
        'confidence_threshold' => 'decimal:2',
        'privacy_blur_enabled' => 'boolean',
        'total_frames' => 'integer',
        'processed_frames' => 'integer',
        'processing_progress' => 'decimal:2',
        'total_detections' => 'integer',
        'average_detections_per_frame' => 'decimal:2',
        'class_counts' => 'array',
        'traffic_timeline' => 'array',
        'peak_minute' => 'integer',
        'peak_count' => 'integer',
        'line_crossing_count' => 'integer',
        'line_crossing_by_class' => 'array',
    ];

    public function getOriginalVideoUrlAttribute(): ?string
    {
        return $this->original_path 
            ? route('video.serve', ['video' => $this->id, 'type' => 'original'])
            : null;
    }

    public function getProcessedVideoUrlAttribute(): ?string
    {
        return $this->processed_path 
            ? route('video.serve', ['video' => $this->id, 'type' => 'processed'])
            : null;
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function isProcessed(): bool
    {
        return $this->status === 'completed' && $this->processed_path !== null;
    }

    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }
        
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;
        
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getMetadataLabelAttribute(): string
    {
        $parts = array_filter([
            $this->location_name,
            $this->time_of_day,
            $this->weather_condition,
        ]);
        
        return !empty($parts) ? implode(' - ', $parts) : 'No metadata';
    }

    public function getProgressPercentageAttribute(): int
    {
        return (int) $this->processing_progress;
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}

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
        'original_filename',
        'original_path',
        'processed_path',
        'file_size',
        'status',
        'error_message',
        'processing_duration',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'processing_duration' => 'integer',
    ];

    public function getOriginalVideoUrlAttribute(): ?string
    {
        return $this->original_path 
            ? Storage::disk('videos')->url($this->original_path) 
            : null;
    }

    public function getProcessedVideoUrlAttribute(): ?string
    {
        return $this->processed_path 
            ? Storage::disk('processed_videos')->url($this->processed_path) 
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
}

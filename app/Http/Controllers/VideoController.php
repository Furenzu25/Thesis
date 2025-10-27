<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    public function serve(Video $video, string $type)
    {
        // Determine which video to serve
        $disk = $type === 'original' ? 'videos' : 'processed_videos';
        $path = $type === 'original' ? $video->original_path : $video->processed_path;

        if (!$path || !Storage::disk($disk)->exists($path)) {
            abort(404, 'Video not found');
        }

        $fullPath = Storage::disk($disk)->path($path);
        $mimeType = 'video/mp4';
        
        // Get file size for range requests
        $size = filesize($fullPath);
        
        return response()->stream(function () use ($fullPath) {
            $stream = fopen($fullPath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}

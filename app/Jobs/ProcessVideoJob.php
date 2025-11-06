<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoInferenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public $timeout = 900; // 15 minutes
    public $tries = 1;
    public $failOnTimeout = true;

    public function __construct(
        public Video $video
    ) {}

    public function handle(VideoInferenceService $inferenceService): void
    {
        try {
            Log::info('Starting video processing', [
                'video_id' => $this->video->id,
                'video_path' => $this->video->original_path,
            ]);

            // Initialize processing state
            $this->video->update([
                'status' => 'processing',
                'processing_progress' => 0,
                'processed_frames' => 0,
            ]);

            // Process video with progress callback
            $success = $inferenceService->processVideo(
                $this->video,
                function($progress, $stats = []) {
                    $updateData = ['processing_progress' => $progress];
                    
                    // Update statistics if provided
                    if (!empty($stats)) {
                        if (isset($stats['processed_frames'])) {
                            $updateData['processed_frames'] = $stats['processed_frames'];
                        }
                        if (isset($stats['total_frames'])) {
                            $updateData['total_frames'] = $stats['total_frames'];
                        }
                        if (isset($stats['total_detections'])) {
                            $updateData['total_detections'] = $stats['total_detections'];
                        }
                        if (isset($stats['average_detections_per_frame'])) {
                            $updateData['average_detections_per_frame'] = $stats['average_detections_per_frame'];
                        }
                    }
                    
                    // Update video record
                    $this->video->fresh()->update($updateData);
                    
                    Log::debug('Processing progress', [
                        'video_id' => $this->video->id,
                        'progress' => $progress,
                        'stats' => $stats,
                    ]);
                }
            );

            if (!$success) {
                throw new \Exception('Video processing returned false');
            }

            Log::info('Video processing completed successfully', [
                'video_id' => $this->video->id,
                'status' => $this->video->fresh()->status,
                'processed_path' => $this->video->fresh()->processed_path,
            ]);

        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            
            // Truncate error message to prevent database errors
            if (strlen($errorMessage) > 5000) {
                $errorMessage = substr($errorMessage, 0, 5000) . '... (truncated)';
            }
            
            Log::error('Exception in video processing', [
                'video_id' => $this->video->id,
                'error' => $errorMessage,
            ]);

            // Update status
            $this->video->fresh()->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $errorMessage = $exception->getMessage();
        
        // Truncate to fit in database TEXT field
        if (strlen($errorMessage) > 5000) {
            $errorMessage = substr($errorMessage, 0, 5000) . '... (truncated)';
        }
        
        Log::error('Video processing job failed', [
            'video_id' => $this->video->id,
            'error' => $errorMessage,
        ]);

        try {
            $this->video->fresh()->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update video status', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

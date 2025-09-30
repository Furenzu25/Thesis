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

            $this->video->update(['status' => 'processing']);

            $success = $inferenceService->processVideo($this->video);

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

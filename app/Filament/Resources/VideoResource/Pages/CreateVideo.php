<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Filament\Resources\VideoResource;
use App\Jobs\ProcessVideoJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;

class CreateVideo extends CreateRecord
{
    protected static string $resource = VideoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Get file info after upload
        if (isset($data['original_path'])) {
            $filePath = $data['original_path'];
            $fullPath = Storage::disk('videos')->path($filePath);
            
            // Get file size
            $data['file_size'] = Storage::disk('videos')->size($filePath);
            
            // Get original filename
            $data['original_filename'] = basename($filePath);
            
            // Extract video properties using PHP video info if available
            try {
                $videoInfo = $this->extractVideoProperties($fullPath);
                $data['duration_seconds'] = $videoInfo['duration'];
                $data['resolution'] = $videoInfo['resolution'];
                $data['video_format'] = $videoInfo['format'];
                
                // Validate duration (warn if > 5 minutes)
                if ($data['duration_seconds'] > 300) { // 5 minutes
                    Notification::make()
                        ->warning()
                        ->title('Long Video Detected')
                        ->body('Video duration exceeds 5 minutes. Processing may take longer.')
                        ->persistent()
                        ->send();
                }
                
            } catch (\Exception $e) {
                // If extraction fails, log it but continue
                \Log::warning('Could not extract video properties: ' . $e->getMessage());
                $data['duration_seconds'] = null;
                $data['resolution'] = 'Unknown';
                $data['video_format'] = pathinfo($filePath, PATHINFO_EXTENSION);
            }
            
            // Set default confidence threshold if not provided
            if (!isset($data['confidence_threshold']) || empty($data['confidence_threshold'])) {
                $data['confidence_threshold'] = 0.25;
            }
            
            // Set default privacy blur if not provided
            if (!isset($data['privacy_blur_enabled'])) {
                $data['privacy_blur_enabled'] = false;
            }
            
            // Set initial progress tracking values
            $data['processing_progress'] = 0;
            $data['processed_frames'] = 0;
        }

        return $data;
    }

    protected function extractVideoProperties(string $videoPath): array
    {
        // Try using getID3 library if available
        if (class_exists('\getID3')) {
            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($videoPath);
            
            return [
                'duration' => isset($fileInfo['playtime_seconds']) ? (int) $fileInfo['playtime_seconds'] : null,
                'resolution' => isset($fileInfo['video']['resolution_x'], $fileInfo['video']['resolution_y']) 
                    ? $fileInfo['video']['resolution_x'] . 'x' . $fileInfo['video']['resolution_y']
                    : 'Unknown',
                'format' => $fileInfo['fileformat'] ?? 'mp4',
            ];
        }
        
        // Fallback: Try using ffprobe if available
        if (function_exists('exec')) {
            $duration = null;
            $resolution = 'Unknown';
            
            // Get duration
            exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($videoPath), $output);
            if (!empty($output[0])) {
                $duration = (int) floatval($output[0]);
            }
            
            // Get resolution
            exec("ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($videoPath), $resOutput);
            if (!empty($resOutput[0])) {
                $resolution = $resOutput[0];
            }
            
            return [
                'duration' => $duration,
                'resolution' => $resolution,
                'format' => pathinfo($videoPath, PATHINFO_EXTENSION),
            ];
        }
        
        // If nothing works, return defaults
        return [
            'duration' => null,
            'resolution' => 'Unknown',
            'format' => pathinfo($videoPath, PATHINFO_EXTENSION),
        ];
    }

    protected function afterCreate(): void
    {
        // Dispatch job to process video
        ProcessVideoJob::dispatch($this->record);
        
        // Show success notification with non-blocking message
        Notification::make()
            ->success()
            ->title('Video Upload Complete')
            ->body('Video is being processed. You may navigate to the Dashboard to monitor progress.')
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
    protected function getCreatedNotificationTitle(): ?string
    {
        return null; // Disable default notification since we have custom one
    }
}

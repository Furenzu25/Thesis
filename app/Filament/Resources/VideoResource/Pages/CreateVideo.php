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
            
            // Extract video properties using OpenCV (via conda Python environment)
            try {
                $videoInfo = $this->extractVideoProperties($fullPath);
                $data['duration_seconds'] = $videoInfo['duration'];
                $data['resolution'] = $videoInfo['resolution'];
                $data['video_format'] = $videoInfo['format'];
                
                // Also store total frames if available
                if (isset($videoInfo['total_frames'])) {
                    $data['total_frames'] = $videoInfo['total_frames'];
                }
                
                // Validate duration (warn if > 5 minutes)
                if ($data['duration_seconds'] && $data['duration_seconds'] > 300) { // 5 minutes
                    Notification::make()
                        ->warning()
                        ->title('Long Video Detected')
                        ->body('Video duration exceeds 5 minutes. Processing may take longer.')
                        ->persistent()
                        ->send();
                }
                
                // Log success
                \Log::info('Video properties extracted', [
                    'duration' => $data['duration_seconds'],
                    'resolution' => $data['resolution'],
                    'total_frames' => $data['total_frames'] ?? 'N/A',
                ]);
                
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
        // Use Python with OpenCV (same environment as inference)
        // This is the most reliable method since we already have conda environment set up
        $pythonPath = '/opt/homebrew/Caskroom/miniforge/base/envs/yolov8_m4/bin/python';
        
        if (file_exists($pythonPath)) {
            try {
                $script = $this->getVideoPropertiesScript();
                $scriptPath = storage_path('app/video_props_script.py');
                file_put_contents($scriptPath, $script);
                
                $command = sprintf(
                    '%s %s %s 2>&1',
                    escapeshellarg($pythonPath),
                    escapeshellarg($scriptPath),
                    escapeshellarg($videoPath)
                );
                
                exec($command, $output, $returnCode);
                
                // Clean up script
                @unlink($scriptPath);
                
                if ($returnCode === 0 && !empty($output)) {
                    $json = implode("\n", $output);
                    $properties = json_decode($json, true);
                    
                    if ($properties && isset($properties['duration'])) {
                        \Log::info('Video properties extracted successfully', $properties);
                        
                        return [
                            'duration' => $properties['duration'],
                            'resolution' => $properties['resolution'],
                            'format' => $properties['format'],
                            'total_frames' => $properties['total_frames'] ?? null,
                            'fps' => $properties['fps'] ?? null,
                        ];
                    }
                }
                
                \Log::warning('Python extraction completed but no valid output', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output ?? [])
                ]);
            } catch (\Exception $e) {
                \Log::warning('Python video extraction failed: ' . $e->getMessage());
            }
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
    
    protected function getVideoPropertiesScript(): string
    {
        return <<<'PYTHON'
import sys
import cv2
import json
import os

def get_video_properties(video_path):
    try:
        cap = cv2.VideoCapture(video_path)
        
        if not cap.isOpened():
            return None
        
        # Get video properties
        frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
        fps = cap.get(cv2.CAP_PROP_FPS)
        width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
        height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
        
        # Calculate duration in seconds
        duration = int(frame_count / fps) if fps > 0 else 0
        
        # Get format from file extension
        file_format = os.path.splitext(video_path)[1][1:]  # Remove the dot
        
        cap.release()
        
        properties = {
            'duration': duration,
            'resolution': f'{width}x{height}',
            'format': file_format,
            'total_frames': frame_count,
            'fps': round(fps, 2)
        }
        
        return properties
        
    except Exception as e:
        print(f"Error: {str(e)}", file=sys.stderr)
        return None

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python script.py <video_path>", file=sys.stderr)
        sys.exit(1)
    
    video_path = sys.argv[1]
    
    if not os.path.exists(video_path):
        print(f"Video file not found: {video_path}", file=sys.stderr)
        sys.exit(1)
    
    properties = get_video_properties(video_path)
    
    if properties:
        print(json.dumps(properties))
        sys.exit(0)
    else:
        sys.exit(1)
PYTHON;
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

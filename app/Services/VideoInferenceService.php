<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VideoInferenceService
{
    private string $modelPath;
    private string $condaEnv = 'yolov8_m4';
    
    public function __construct()
    {
        $this->modelPath = $this->getModelPath();
    }
    
    public function processVideo(Video $video, ?callable $progressCallback = null): bool
    {
        $startTime = time();
        
        try {
            // Validate model exists (skip check for built-in YOLO models)
            if (!$this->isBuiltInModel($this->modelPath) && !file_exists($this->modelPath)) {
                throw new \Exception("Model file not found at: {$this->modelPath}");
            }

            // Get paths
            $originalPath = Storage::disk('videos')->path($video->original_path);
            $processedFilename = pathinfo($video->original_filename, PATHINFO_FILENAME) 
                . '_processed_' . time() . '.mp4';
            $processedPath = Storage::disk('processed_videos')->path($processedFilename);

            // Ensure output directory exists
            $outputDir = dirname($processedPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Run inference with progress tracking
            $stats = $this->runInference($originalPath, $processedPath, $video, $progressCallback);

            // Verify output was created
            if (!file_exists($processedPath)) {
                throw new \Exception("Processed video was not created");
            }

            // Update video record with final statistics
            $updateData = [
                'processed_path' => $processedFilename,
                'status' => 'completed',
                'processing_duration' => time() - $startTime,
                'processing_progress' => 100,
                'error_message' => null,
            ];
            
            // Add final statistics if captured
            if (!empty($stats)) {
                $updateData = array_merge($updateData, $stats);
            }
            
            $video->update($updateData);

            return true;

        } catch (\Exception $e) {
            Log::error('Video inference failed', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);

            $video->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processing_duration' => time() - $startTime,
            ]);

            return false;
        }
    }

    private function runInference(string $inputPath, string $outputPath, Video $video, ?callable $progressCallback = null): array
    {
        // Python script for YOLO inference
        $script = $this->getInferenceScript();
        $scriptPath = storage_path('app/inference_script.py');
        file_put_contents($scriptPath, $script);
        
        // Verify script was created
        if (!file_exists($scriptPath)) {
            throw new \Exception("Failed to create inference script at: {$scriptPath}");
        }
        
        Log::info('Created inference script at: ' . $scriptPath);

        // Get conda Python path
        $condaPath = $this->getCondaPythonPath();

        // Change to Laravel project directory before running
        $projectPath = base_path();

        // Build command with confidence threshold from video
        $confidenceThreshold = $video->confidence_threshold ?? 0.25;
        
        $command = sprintf(
            'cd %s && %s %s --model %s --source %s --output %s --conf %s 2>&1',
            escapeshellarg($projectPath),
            escapeshellarg($condaPath),
            escapeshellarg($scriptPath),
            escapeshellarg($this->modelPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            $confidenceThreshold
        );

        Log::info('Running inference command', [
            'command' => $command,
            'working_dir' => $projectPath,
            'confidence_threshold' => $confidenceThreshold,
        ]);

        $result = Process::timeout(600)->run($command);

        Log::info('Inference completed', [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode(),
        ]);

        if (!$result->successful()) {
            $output = $result->output();
            
            // Log full output but throw shorter message
            Log::error('Inference failed', ['full_output' => $output]);
            
            // Get last few lines for error message
            $lines = explode("\n", $output);
            $errorLines = array_slice($lines, -10);
            $shortError = implode("\n", $errorLines);
            
            throw new \Exception("Inference failed. Last output: " . $shortError);
        }

        // Parse statistics from output
        $stats = $this->parseInferenceOutput($result->output(), $progressCallback);

        // Clean up script
        @unlink($scriptPath);
        
        return $stats;
    }
    
    private function parseInferenceOutput(string $output, ?callable $progressCallback = null): array
    {
        $stats = [
            'total_frames' => 0,
            'processed_frames' => 0,
            'total_detections' => 0,
            'average_detections_per_frame' => 0.0,
        ];
        
        $lines = explode("\n", $output);
        $detectionCounts = [];
        
        foreach ($lines as $line) {
            // Parse frame processing lines: "Frame 30: 5 detections"
            if (preg_match('/Frame\s+(\d+):\s+(\d+)\s+detections/', $line, $matches)) {
                $frameNumber = (int)$matches[1];
                $detectionCount = (int)$matches[2];
                
                $stats['processed_frames'] = $frameNumber;
                $detectionCounts[] = $detectionCount;
                
                // Calculate progress and update callback
                if ($progressCallback && $stats['total_frames'] > 0) {
                    $progress = round(($frameNumber / $stats['total_frames']) * 100, 2);
                    $progressCallback($progress, [
                        'processed_frames' => $frameNumber,
                        'total_frames' => $stats['total_frames'],
                    ]);
                }
            }
            
            // Parse total frames: "[INFO] Processed 180 frames"
            if (preg_match('/\[INFO\]\s+Processed\s+(\d+)\s+frames/', $line, $matches)) {
                $stats['total_frames'] = (int)$matches[1];
                $stats['processed_frames'] = (int)$matches[1];
            }
            
            // Parse JSON statistics if present
            if (preg_match('/\[STATS\]\s+(.+)/', $line, $matches)) {
                $jsonStats = json_decode($matches[1], true);
                if ($jsonStats) {
                    $stats = array_merge($stats, $jsonStats);
                }
            }
        }
        
        // Calculate total detections and average
        if (!empty($detectionCounts)) {
            $stats['total_detections'] = array_sum($detectionCounts);
            $stats['average_detections_per_frame'] = round($stats['total_detections'] / count($detectionCounts), 2);
        }
        
        return $stats;
    }

    private function getModelPath(): string
    {
        // Try custom model paths in order of preference
        // Prioritize the exact path from the reference script first
        $possiblePaths = [
            '/Users/jfrtenebroso/Developer/Thesis/2025-10-18_stage6_final/weights/best.pt',  // Reference script path
            '/Users/jfrtenebroso/Developer/Thesis-Yolov8/best.pt',            
            '/Users/jfrtenebroso/Developer/LaravelDevelopment/Thesis/model/best.pt',
            base_path('model/best.pt'),
            // Fallback to YOLO's built-in models if no custom model found
            'yolov8x.pt',  // Extra large model (matches your custom yolov8x)
            'yolov8m.pt',  // Medium model
            'yolov8n.pt',  // Nano model (fastest)
            'yolov8s.pt',  // Small model
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                Log::info("Using model at: {$path}");
                return $path;
            }
        }

        // If no custom model found, return a YOLO built-in model name
        // YOLO will automatically download it when first used
        Log::info("No custom model found, using built-in yolov8m.pt");
        return 'yolov8m.pt';
    }

    private function isBuiltInModel(string $modelPath): bool
    {
        // List of YOLO built-in model identifiers
        $builtInModels = [
            'yolov8n.pt', 'yolov8s.pt', 'yolov8m.pt', 'yolov8l.pt', 'yolov8x.pt',
            'yolov8n-cls.pt', 'yolov8s-cls.pt', 'yolov8m-cls.pt', 'yolov8l-cls.pt', 'yolov8x-cls.pt',
            'yolov8n-seg.pt', 'yolov8s-seg.pt', 'yolov8m-seg.pt', 'yolov8l-seg.pt', 'yolov8x-seg.pt',
        ];
        
        return in_array(basename($modelPath), $builtInModels);
    }

    private function getCondaPythonPath(): string
    {
        // Hardcode your exact conda Python path
        $pythonPath = '/opt/homebrew/Caskroom/miniforge/base/envs/yolov8_m4/bin/python';
        
        if (file_exists($pythonPath)) {
            Log::info('Using hardcoded conda Python at: ' . $pythonPath);
            return $pythonPath;
        }
        
        throw new \Exception("Python not found at: {$pythonPath}");
    }

    private function getInferenceScript(): string
    {
        return <<<'PYTHON'
"""
YOLOv8 Inference Script - Optimized for Mac Silicon M4
Uses MPS (Metal Performance Shaders) for GPU acceleration on Apple Silicon
Enhanced with progress tracking and detection statistics
"""
import sys
import os
import argparse
import json
from pathlib import Path
from ultralytics import YOLO
import shutil
import tempfile
import cv2


def get_video_frame_count(video_path):
    """Get total frame count using OpenCV"""
    try:
        cap = cv2.VideoCapture(video_path)
        frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
        cap.release()
        return frame_count
    except Exception as e:
        print(f"[WARNING] Could not get frame count: {str(e)}", file=sys.stderr)
        return 0


def main():
    parser = argparse.ArgumentParser(description='YOLO inference for Mac Silicon M4')
    parser.add_argument('--model', required=True, help='Path to model file')
    parser.add_argument('--source', required=True, help='Path to input video')
    parser.add_argument('--output', required=True, help='Path to output video')
    parser.add_argument('--conf', type=float, default=0.25, help='Confidence threshold')
    args = parser.parse_args()

    print(f"[INFO] Python: {sys.version}", file=sys.stderr)
    print(f"[INFO] Working directory: {os.getcwd()}", file=sys.stderr)
    
    # Detect device - prioritize MPS for Mac Silicon
    device = 'cpu'
    try:
        import torch
        if torch.backends.mps.is_available():
            device = 'mps'
            print(f"[INFO] Using MPS (Metal Performance Shaders) - Mac GPU acceleration", file=sys.stderr)
        else:
            print(f"[INFO] MPS not available, using CPU", file=sys.stderr)
    except Exception as e:
        print(f"[INFO] Could not detect MPS, using CPU: {str(e)}", file=sys.stderr)
    
    # Validate model file
    if not os.path.exists(args.model):
        print(f"[ERROR] Model not found: {args.model}", file=sys.stderr)
        sys.exit(1)
    
    model_size = os.path.getsize(args.model) / (1024 * 1024)
    print(f"[INFO] Model: {args.model} ({model_size:.1f} MB)", file=sys.stderr)
    print(f"[INFO] Confidence threshold: {args.conf}", file=sys.stderr)
    
    # Get total frame count for progress tracking
    total_frames = get_video_frame_count(args.source)
    if total_frames > 0:
        print(f"[INFO] Total frames: {total_frames}", file=sys.stderr)
    
    # Load model
    try:
        model = YOLO(args.model)
        print(f"[INFO] Model loaded successfully", file=sys.stderr)
    except Exception as e:
        print(f"[ERROR] Failed to load model: {str(e)}", file=sys.stderr)
        sys.exit(1)
    
    # Prepare output
    output_dir = os.path.dirname(args.output)
    os.makedirs(output_dir, exist_ok=True)
    
    temp_dir = tempfile.mkdtemp()
    print(f"[INFO] Processing: {args.source}", file=sys.stderr)
    
    try:
        # Run inference with Mac Silicon optimizations
        results = model.predict(
            source=args.source,
            device=device,       # Use MPS or CPU
            imgsz=832,           # Match training size
            conf=args.conf,      # Use specified confidence threshold
            iou=0.45,            # NMS threshold
            save=True,
            show_labels=True,
            show_conf=True,
            show=False,
            stream=True,         # Memory efficient
            project=temp_dir,
            name='predict',
            exist_ok=True,
            verbose=False,
        )
        
        # Process frames and collect statistics
        frame_count = 0
        detection_counts = []
        
        for result in results:
            frame_count += 1
            detections = len(result.boxes) if result.boxes is not None else 0
            detection_counts.append(detections)
            
            # Report progress every 30 frames
            if frame_count % 30 == 0:
                print(f"   Frame {frame_count}: {detections} detections", file=sys.stderr)
        
        # Calculate final statistics
        total_detections = sum(detection_counts)
        avg_detections = total_detections / frame_count if frame_count > 0 else 0
        
        print(f"[INFO] Processed {frame_count} frames", file=sys.stderr)
        print(f"[INFO] Total detections: {total_detections}", file=sys.stderr)
        print(f"[INFO] Average detections per frame: {avg_detections:.2f}", file=sys.stderr)
        
        # Output structured statistics for parsing
        stats = {
            'total_frames': frame_count,
            'processed_frames': frame_count,
            'total_detections': total_detections,
            'average_detections_per_frame': round(avg_detections, 2)
        }
        print(f"[STATS] {json.dumps(stats)}", file=sys.stderr)
        
        # Move output video
        predict_dir = Path(temp_dir) / 'predict'
        if not predict_dir.exists():
            print(f"[ERROR] Output directory not created", file=sys.stderr)
            sys.exit(1)
        
        video_files = list(predict_dir.glob('*.mp4')) + list(predict_dir.glob('*.avi'))
        if not video_files:
            print(f"[ERROR] No output video found", file=sys.stderr)
            sys.exit(1)
        
        shutil.move(str(video_files[0]), args.output)
        print(f"[SUCCESS] Output: {args.output}", file=sys.stderr)
        
    except Exception as e:
        print(f"[ERROR] {str(e)}", file=sys.stderr)
        import traceback
        traceback.print_exc(file=sys.stderr)
        sys.exit(1)
    finally:
        if os.path.exists(temp_dir):
            shutil.rmtree(temp_dir, ignore_errors=True)


if __name__ == '__main__':
    main()
PYTHON;
    }
}

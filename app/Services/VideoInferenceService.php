<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VideoInferenceService
{
    // ========================================
    // CONFIGURATION - Update these paths for your system
    // ========================================
    
    /**
     * Windows Python Path
     * Find your conda Python by running: conda info --envs
     * Then locate python.exe in that environment folder
     * Example: C:\Users\YourName\miniconda3\envs\yolov8_m4\python.exe
     */
    private const WINDOWS_PYTHON_PATH = 'C:\Users\Janfl\.conda\envs\yolov8_py312\python.exe';
    
    /**
     * Mac Python Path (for cross-platform compatibility)
     */
    private const MAC_PYTHON_PATH = '/opt/homebrew/Caskroom/miniforge/base/envs/yolov8_m4/bin/python';
    
    /**
     * Custom Model Path (Stage 7)
     * Already configured for your A: drive location
     */
    private const CUSTOM_MODEL_PATH = 'A:/Opencv/thesis-yolov8/runs/2025-10-28_033053_stage7_chonghua/weights/best.pt';
    
    // ========================================
    // END CONFIGURATION
    // ========================================
    
    private string $modelPath;
    private string $condaEnv = 'yolov8_m4';
    
    public function __construct()
    {
        $this->modelPath = $this->getModelPath();
    }
    
    /**
     * Normalize traffic direction from various formats to canonical values
     */
    private function normalizeDirection(?string $dir): string
    {
        $map = [
            'l2r' => 'left_to_right',
            'left-right' => 'left_to_right',
            'left_to_right' => 'left_to_right',
            'leftright' => 'left_to_right',
            
            'r2l' => 'right_to_left',
            'right-left' => 'right_to_left',
            'right_to_left' => 'right_to_left',
            'rightleft' => 'right_to_left',
            
            't2b' => 'top_to_bottom',
            'top-bottom' => 'top_to_bottom',
            'top_to_bottom' => 'top_to_bottom',
            'topbottom' => 'top_to_bottom',
            
            'b2t' => 'bottom_to_top',
            'bottom-top' => 'bottom_to_top',
            'bottom_to_top' => 'bottom_to_top',
            'bottomtop' => 'bottom_to_top',
        ];
        
        $key = strtolower(trim((string)$dir));
        return $map[$key] ?? 'none';
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
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // Build command with confidence threshold, privacy blur, and traffic direction
        $confidenceThreshold = $video->confidence_threshold ?? 0.25;
        $privacyBlur = $video->privacy_blur_enabled ? '--blur' : '';
        $trafficDirection = $this->normalizeDirection($video->traffic_direction ?? 'none');
        
        // Build command based on OS
        if ($isWindows) {
            // Windows PowerShell command
            $command = sprintf(
                'cd /d %s && %s %s --model %s --source %s --output %s --conf %s --direction %s %s 2>&1',
                escapeshellarg($projectPath),
                escapeshellarg($condaPath),
                escapeshellarg($scriptPath),
                escapeshellarg($this->modelPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath),
                $confidenceThreshold,
                escapeshellarg($trafficDirection),
                $privacyBlur
            );
        } else {
            // Unix/Mac command
            $command = sprintf(
                'cd %s && %s %s --model %s --source %s --output %s --conf %s --direction %s %s 2>&1',
                escapeshellarg($projectPath),
                escapeshellarg($condaPath),
                escapeshellarg($scriptPath),
                escapeshellarg($this->modelPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath),
                $confidenceThreshold,
                escapeshellarg($trafficDirection),
                $privacyBlur
            );
        }

        Log::info('Running inference command', [
            'command' => $command,
            'working_dir' => $projectPath,
            'confidence_threshold' => $confidenceThreshold,
            'privacy_blur_enabled' => $video->privacy_blur_enabled,
            'traffic_direction' => $trafficDirection,
            'os' => $isWindows ? 'Windows' : 'Unix',
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
            'class_counts' => [],
            'traffic_timeline' => [],
            'peak_minute' => null,
            'peak_count' => null,
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
                if ($jsonStats && is_array($jsonStats)) {
                    // Merge all statistics from JSON
                    $stats = array_merge($stats, $jsonStats);
                    
                    Log::info('Parsed detection statistics', [
                        'class_counts' => $stats['class_counts'] ?? [],
                        'peak_minute' => $stats['peak_minute'] ?? null,
                        'peak_count' => $stats['peak_count'] ?? null,
                    ]);
                }
            }
        }
        
        // Calculate total detections and average if not provided by JSON stats
        if (empty($stats['total_detections']) && !empty($detectionCounts)) {
            $stats['total_detections'] = array_sum($detectionCounts);
            $stats['average_detections_per_frame'] = round($stats['total_detections'] / count($detectionCounts), 2);
        }
        
        return $stats;
    }

    private function getModelPath(): string
    {
        // Try custom model paths in order of preference
        $possiblePaths = [
            // Custom model from configuration (Stage 7 - prioritized)
            self::CUSTOM_MODEL_PATH,
            str_replace('/', '\\', self::CUSTOM_MODEL_PATH), // Try backslashes
            
            // Alternative Windows locations
            base_path('model/best.pt'),
            base_path('model\\best.pt'),
            'C:/models/best.pt',
            'C:\\models\\best.pt',
            
            // Mac paths (for cross-platform compatibility)
            '/Users/jfrtenebroso/Developer/Thesis/2025-10-18_stage6_final/weights/best.pt',
            '/Users/jfrtenebroso/Developer/Thesis-Yolov8/best.pt',
            
            // Fallback to YOLO's built-in models if no custom model found
            'yolov8x.pt',  // Extra large model
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
        Log::warning("No custom model found, using built-in yolov8m.pt");
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
        // Detect OS and set appropriate Python path
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // First, try the configured path from constants
            if (file_exists(self::WINDOWS_PYTHON_PATH)) {
                Log::info('Using configured Windows Python at: ' . self::WINDOWS_PYTHON_PATH);
                return self::WINDOWS_PYTHON_PATH;
            }
            
            // Windows conda Python paths (try multiple locations)
            $windowsPaths = [
                // User's .conda directory (common for newer conda installations)
                'C:\\Users\\' . get_current_user() . '\\.conda\\envs\\yolov8_py312\\python.exe',
                'C:\\Users\\' . get_current_user() . '\\.conda\\envs\\yolov8_m4\\python.exe',
                'C:\\Users\\' . get_current_user() . '\\.conda\\envs\\yolov8\\python.exe',
                
                // Common conda installation paths on Windows
                'C:\\Users\\' . get_current_user() . '\\miniconda3\\envs\\yolov8_py312\\python.exe',
                'C:\\Users\\' . get_current_user() . '\\miniconda3\\envs\\yolov8_m4\\python.exe',
                'C:\\Users\\' . get_current_user() . '\\anaconda3\\envs\\yolov8_py312\\python.exe',
                'C:\\Users\\' . get_current_user() . '\\anaconda3\\envs\\yolov8_m4\\python.exe',
                'C:\\ProgramData\\miniconda3\\envs\\yolov8_m4\\python.exe',
                'C:\\ProgramData\\Anaconda3\\envs\\yolov8_m4\\python.exe',
                
                // Try with different env names
                'C:\\Users\\' . get_current_user() . '\\miniconda3\\envs\\yolov8\\python.exe',
                'C:\\Users\\' . get_current_user() . '\\anaconda3\\envs\\yolov8\\python.exe',
                
                // Laragon's Python (if available)
                'C:\\laragon\\bin\\python\\python.exe',
            ];
            
            foreach ($windowsPaths as $path) {
                if (file_exists($path)) {
                    Log::info('Using Windows Python at: ' . $path);
                    return $path;
                }
            }
            
            // If no conda found, try system Python
            $systemPython = trim(shell_exec('where python 2>NUL') ?? '');
            if (!empty($systemPython)) {
                $pythonPath = explode("\n", $systemPython)[0];
                if (file_exists(trim($pythonPath))) {
                    Log::info('Using system Python at: ' . $pythonPath);
                    return trim($pythonPath);
                }
            }
            
            $errorMsg = "Python not found. Please:\n";
            $errorMsg .= "1. Install Miniconda/Anaconda with yolov8_m4 environment\n";
            $errorMsg .= "2. Install required packages: pip install ultralytics opencv-python torch\n";
            $errorMsg .= "3. Update WINDOWS_PYTHON_PATH in app/Services/VideoInferenceService.php (line ~20)\n";
            $errorMsg .= "   Current configured path: " . self::WINDOWS_PYTHON_PATH;
            
            throw new \Exception($errorMsg);
            
        } else {
            // Mac/Linux Python path - try configured path first
            if (file_exists(self::MAC_PYTHON_PATH)) {
                Log::info('Using configured Mac Python at: ' . self::MAC_PYTHON_PATH);
                return self::MAC_PYTHON_PATH;
            }
            
            throw new \Exception("Python not found at: " . self::MAC_PYTHON_PATH);
        }
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


def apply_privacy_blur(frame, face_cascade):
    """Apply Gaussian blur to faces and license plate regions
    
    Returns:
        tuple: (blurred_frame, faces_count, plates_count)
    """
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    
    # Detect faces using Haar Cascade
    faces = face_cascade.detectMultiScale(
        gray,
        scaleFactor=1.1,
        minNeighbors=5,
        minSize=(30, 30)
    )
    
    faces_blurred = 0
    # Blur detected faces
    for (x, y, w, h) in faces:
        # Expand region slightly for better coverage
        padding = int(h * 0.1)
        y1 = max(0, y - padding)
        y2 = min(frame.shape[0], y + h + padding)
        x1 = max(0, x - padding)
        x2 = min(frame.shape[1], x + w + padding)
        
        # Apply heavy Gaussian blur
        face_region = frame[y1:y2, x1:x2]
        if face_region.size > 0:
            blurred_face = cv2.GaussianBlur(face_region, (99, 99), 30)
            frame[y1:y2, x1:x2] = blurred_face
            faces_blurred += 1
    
    # Detect potential license plates (rectangular contours)
    edges = cv2.Canny(gray, 100, 200)
    contours, _ = cv2.findContours(edges, cv2.RETR_TREE, cv2.CHAIN_APPROX_SIMPLE)
    
    plates_blurred = 0
    for contour in contours:
        # Get bounding rectangle
        x, y, w, h = cv2.boundingRect(contour)
        
        # Filter based on aspect ratio typical of license plates
        aspect_ratio = w / float(h) if h > 0 else 0
        if 2.0 < aspect_ratio < 5.5 and w > 60 and h > 15:
            # Apply blur to potential license plate
            plate_region = frame[y:y+h, x:x+w]
            if plate_region.size > 0:
                blurred_plate = cv2.GaussianBlur(plate_region, (51, 51), 30)
                frame[y:y+h, x:x+w] = blurred_plate
                plates_blurred += 1
    
    return frame, faces_blurred, plates_blurred


def get_line_coordinates(direction, width, height):
    """Get line coordinates based on traffic direction"""
    margin = 150  # Pixels from edge (counting line position)
    
    if direction == 'left_to_right':
        # Vertical line on the right side
        x = width - margin
        return [(x, 0), (x, height)]
    elif direction == 'right_to_left':
        # Vertical line on the left side
        x = margin
        return [(x, 0), (x, height)]
    elif direction == 'top_to_bottom':
        # Horizontal line on the bottom
        y = height - margin
        return [(0, y), (width, y)]
    elif direction == 'bottom_to_top':
        # Horizontal line on the top
        y = margin
        return [(0, y), (width, y)]
    else:
        return None


def is_in_entry_zone(pos, line_coords, direction, margin=120):
    """Check if vehicle is in the entry zone (before the line)
    
    Entry zone is the area BEFORE the counting line where vehicles must be detected first.
    Margin defines how far before the line the entry zone extends.
    """
    if pos is None or line_coords is None:
        return False
    
    x, y = pos
    (x1, y1), (x2, y2) = line_coords
    
    if direction == 'left_to_right':
        # Entry zone: Left side of the line
        # Vehicle must be well to the left of the counting line
        return x < (x1 - margin)
    elif direction == 'right_to_left':
        # Entry zone: Right side of the line
        # Vehicle must be well to the right of the counting line
        return x > (x1 + margin)
    elif direction == 'top_to_bottom':
        # Entry zone: Top side of the line
        # Vehicle must be well above the counting line
        return y < (y1 - margin)
    elif direction == 'bottom_to_top':
        # Entry zone: Bottom side of the line
        # Vehicle must be well below the counting line
        return y > (y1 + margin)
    
    return False


def is_in_exit_zone(pos, line_coords, direction, margin=60):
    """Check if vehicle is in the exit zone (after the line)
    
    Exit zone is the area AFTER the counting line.
    Vehicle must reach this zone to confirm it fully crossed.
    """
    if pos is None or line_coords is None:
        return False
    
    x, y = pos
    (x1, y1), (x2, y2) = line_coords
    
    if direction == 'left_to_right':
        # Exit zone: Right side of the line
        # Vehicle must be clearly past the counting line
        return x > (x1 + margin)
    elif direction == 'right_to_left':
        # Exit zone: Left side of the line
        # Vehicle must be clearly past the counting line
        return x < (x1 - margin)
    elif direction == 'top_to_bottom':
        # Exit zone: Bottom side of the line
        # Vehicle must be clearly past the counting line
        return y > (y1 + margin)
    elif direction == 'bottom_to_top':
        # Exit zone: Top side of the line
        # Vehicle must be clearly past the counting line
        return y < (y1 - margin)
    
    return False


def is_crossing_line(prev_pos, curr_pos, line_coords, direction, threshold=10):
    """Check if object crossed the line between two positions
    
    Vehicle must clearly move from one side to the other.
    Threshold ensures we don't count vehicles just touching the line.
    """
    if prev_pos is None or curr_pos is None or line_coords is None:
        return False
    
    # Get center points
    px, py = prev_pos
    cx, cy = curr_pos
    
    # Line coordinates
    (x1, y1), (x2, y2) = line_coords
    
    if direction == 'left_to_right':
        # Vertical line: Vehicle moving from left to right
        # Previous position must be clearly LEFT of line
        # Current position must be clearly RIGHT of line
        return px < (x1 - threshold) and cx > (x1 + threshold)
    
    elif direction == 'right_to_left':
        # Vertical line: Vehicle moving from right to left
        # Previous position must be clearly RIGHT of line
        # Current position must be clearly LEFT of line
        return px > (x1 + threshold) and cx < (x1 - threshold)
    
    elif direction == 'top_to_bottom':
        # Horizontal line: Vehicle moving from top to bottom
        # Previous position must be clearly ABOVE line
        # Current position must be clearly BELOW line
        return py < (y1 - threshold) and cy > (y1 + threshold)
    
    elif direction == 'bottom_to_top':
        # Horizontal line: Vehicle moving from bottom to top
        # Previous position must be clearly BELOW line
        # Current position must be clearly ABOVE line
        return py > (y1 + threshold) and cy < (y1 - threshold)
    
    return False


def main():
    parser = argparse.ArgumentParser(description='YOLO inference for Mac Silicon M4')
    parser.add_argument('--model', required=True, help='Path to model file')
    parser.add_argument('--source', required=True, help='Path to input video')
    parser.add_argument('--output', required=True, help='Path to output video')
    parser.add_argument('--conf', type=float, default=0.25, help='Confidence threshold')
    parser.add_argument('--direction', default='none', help='Traffic direction for line crossing')
    parser.add_argument('--blur', action='store_true', help='Enable privacy blur for faces and plates')
    args = parser.parse_args()

    print(f"[INFO] Python: {sys.version}", file=sys.stderr)
    print(f"[INFO] Working directory: {os.getcwd()}", file=sys.stderr)
    print(f"[INFO] Traffic direction: {args.direction}", file=sys.stderr)
    
    # Line crossing setup
    enable_line_crossing = args.direction != 'none'
    tracked_objects = {}  # Store positions and states of tracked objects
    crossed_ids = set()  # Track IDs that have crossed the line
    line_crossing_by_class = {}  # Count crossings per class
    entry_zone_ids = set()  # Track IDs that have been detected in entry zone
    
    # Privacy blur setup
    face_cascade = None
    if args.blur:
        print(f"[INFO] Privacy blur enabled", file=sys.stderr)
        # Load Haar Cascade for face detection
        cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
        face_cascade = cv2.CascadeClassifier(cascade_path)
        if face_cascade.empty():
            print(f"[WARNING] Could not load face cascade, privacy blur may not work", file=sys.stderr)
    
    # Detect device - prioritize CUDA (NVIDIA GPU) for Windows, then MPS for Mac Silicon
    device = 'cpu'
    try:
        import torch
        if torch.cuda.is_available():
            device = 'cuda'
            gpu_name = torch.cuda.get_device_name(0)
            gpu_memory = torch.cuda.get_device_properties(0).total_memory / (1024**3)
            print(f"[INFO] Using CUDA GPU: {gpu_name} ({gpu_memory:.1f} GB)", file=sys.stderr)
            print(f"[INFO] CUDA version: {torch.version.cuda}", file=sys.stderr)
        elif torch.backends.mps.is_available():
            device = 'mps'
            print(f"[INFO] Using MPS (Metal Performance Shaders) - Mac GPU acceleration", file=sys.stderr)
        else:
            print(f"[INFO] No GPU detected, using CPU (this will be slower)", file=sys.stderr)
            print(f"[INFO] To enable GPU: Install CUDA toolkit and PyTorch with CUDA support", file=sys.stderr)
    except Exception as e:
        print(f"[WARNING] Could not detect GPU, using CPU: {str(e)}", file=sys.stderr)
    
    # Validate model file (allow built-in YOLO models)
    BUILT_INS = {
        "yolov8n.pt", "yolov8s.pt", "yolov8m.pt", "yolov8l.pt", "yolov8x.pt",
        "yolov8n-cls.pt", "yolov8s-cls.pt", "yolov8m-cls.pt", "yolov8l-cls.pt", "yolov8x-cls.pt",
        "yolov8n-seg.pt", "yolov8s-seg.pt", "yolov8m-seg.pt", "yolov8l-seg.pt", "yolov8x-seg.pt",
    }
    if not (os.path.exists(args.model) or args.model in BUILT_INS):
        print(f"[ERROR] Model not found: {args.model}", file=sys.stderr)
        sys.exit(1)
    
    # Show model info
    if os.path.exists(args.model):
        model_size = os.path.getsize(args.model) / (1024 * 1024)
        print(f"[INFO] Model: {args.model} ({model_size:.1f} MB)", file=sys.stderr)
    else:
        print(f"[INFO] Model: {args.model} (built-in, will be auto-downloaded if needed)", file=sys.stderr)
    print(f"[INFO] Confidence threshold: {args.conf}", file=sys.stderr)
    
    # Get video properties
    cap = cv2.VideoCapture(args.source)
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    fps = cap.get(cv2.CAP_PROP_FPS)
    video_width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    video_height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    cap.release()
    
    if total_frames > 0:
        print(f"[INFO] Total frames: {total_frames}", file=sys.stderr)
        print(f"[INFO] Video FPS: {fps:.2f}", file=sys.stderr)
        print(f"[INFO] Resolution: {video_width}x{video_height}", file=sys.stderr)
    
    # Clamp FPS when zero
    if fps <= 0:
        fps = 30.0
        print(f"[INFO] FPS was 0, using default: {fps}", file=sys.stderr)
    
    # Dynamic margins based on video resolution (for more robust line crossing detection)
    short_side = min(video_width, video_height)
    entry_margin = max(40, int(short_side * 0.08))   # ~8% of short side (>=40 px)
    exit_margin = max(30, int(short_side * 0.05))    # ~5% of short side (>=30 px)
    cross_thresh = max(12, int(short_side * 0.015))  # crossing threshold (>=12 px)
    print(f"[INFO] Dynamic margins - Entry: {entry_margin}px, Exit: {exit_margin}px, Crossing: {cross_thresh}px", file=sys.stderr)
    
    # Get line coordinates if line crossing is enabled
    line_coords = get_line_coordinates(args.direction, video_width, video_height) if enable_line_crossing else None
    if line_coords:
        print(f"[INFO] Line crossing enabled: {args.direction}", file=sys.stderr)
        print(f"[INFO] Line position: {line_coords}", file=sys.stderr)
    
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
        # Always use tracking for accurate unique vehicle counting
        results = model.track(
            source=args.source,
            device=device,
            imgsz=832,
            conf=args.conf,
            iou=0.45,
            show=False,
            stream=True,
            tracker="bytetrack.yaml",  # Use ByteTrack for better tracking
            persist=True,  # Persist tracks across frames
            verbose=False,
        )
        
        if enable_line_crossing:
            print(f"[INFO] Using YOLO tracking with line crossing detection ({args.direction})", file=sys.stderr)
        else:
            print(f"[INFO] Using YOLO tracking for vehicle counting", file=sys.stderr)
        
        # Process frames and collect detailed statistics
        frame_count = 0
        detection_counts = []
        class_counts = {}  # Track detections per class
        unique_ids_per_class = {}  # Track unique vehicle IDs per class
        per_minute_counts = {}  # Track detections per minute
        processed_frames = []  # Store frames if blur is enabled
        total_faces_blurred = 0
        total_plates_blurred = 0
        
        for result in results:
            frame_count += 1
            detections = len(result.boxes) if result.boxes is not None else 0
            detection_counts.append(detections)
            
            # Calculate current minute
            current_minute = int((frame_count / fps) / 60) if fps > 0 else 0
            
            # Initialize minute counter
            if current_minute not in per_minute_counts:
                per_minute_counts[current_minute] = 0
            
            # Process detections and tracking
            if result.boxes is not None:
                for i, box in enumerate(result.boxes):
                    cls_id = int(box.cls[0])
                    class_name = model.names[cls_id]
                    
                    # Increment minute counter (for traffic timeline)
                    per_minute_counts[current_minute] += 1
                    
                    # Always track unique IDs for accurate vehicle counting
                    if hasattr(result.boxes, 'id') and result.boxes.id is not None:
                        track_id = int(result.boxes.id[i])
                        
                        # Track unique IDs per class (for accurate vehicle counting)
                        if class_name not in unique_ids_per_class:
                            unique_ids_per_class[class_name] = set()
                        unique_ids_per_class[class_name].add(track_id)
                        
                        # Line crossing detection (only if enabled)
                        if enable_line_crossing:
                            # Get bounding box center
                            x1, y1, x2, y2 = box.xyxy[0]
                            center_x = int((x1 + x2) / 2)
                            center_y = int((y1 + y2) / 2)
                            curr_pos = (center_x, center_y)
                            
                            # Check if vehicle is in entry zone (first time detection)
                            if track_id not in entry_zone_ids:
                                if is_in_entry_zone(curr_pos, line_coords, args.direction, entry_margin):
                                    entry_zone_ids.add(track_id)
                                    print(f"   [ENTRY] ID {track_id} ({class_name}) entered tracking zone", file=sys.stderr)
                            
                            # Crossing logic (more permissive)
                            if track_id not in crossed_ids and track_id in tracked_objects:
                                prev_pos = tracked_objects[track_id]['position']
                                
                                # Check if line was crossed
                                if is_crossing_line(prev_pos, curr_pos, line_coords, args.direction, cross_thresh):
                                    crossed = False
                                    
                                    # If we had entry zone detection, enforce exit zone
                                    if track_id in entry_zone_ids:
                                        crossed = is_in_exit_zone(curr_pos, line_coords, args.direction, exit_margin)
                                    else:
                                        # Fallback: allow counting on clear cross even if entry was missed
                                        # This handles cases where vehicle is first detected near/on the line
                                        crossed = True
                                    
                                    if crossed:
                                        crossed_ids.add(track_id)
                                        
                                        # Increment line crossing counter for this class
                                        if class_name not in line_crossing_by_class:
                                            line_crossing_by_class[class_name] = 0
                                        line_crossing_by_class[class_name] += 1
                                        
                                        entry_status = "with entry" if track_id in entry_zone_ids else "without entry"
                                        print(f"   [CROSSING] ID {track_id} ({class_name}) crossed line at frame {frame_count} ({entry_status})", file=sys.stderr)
                            
                            # Update tracked position
                            tracked_objects[track_id] = {
                                'position': curr_pos,
                                'class': class_name
                            }
            
            # Get annotated frame (always available with tracking)
            annotated_frame = result.plot()
            
            # Draw line crossing visualizations if enabled
            if enable_line_crossing and line_coords:
                (x1, y1), (x2, y2) = line_coords
                # Use the dynamic entry_margin calculated earlier
                
                # Draw the main counting line (GREEN, thick)
                cv2.line(annotated_frame, (x1, y1), (x2, y2), (0, 255, 0), 4)
                
                # Draw entry zone indicator (YELLOW) for all directions
                if args.direction == 'left_to_right':
                    # Vertical lines: Entry zone on LEFT side
                    entry_x = x1 - entry_margin
                    cv2.line(annotated_frame, (entry_x, y1), (entry_x, y2), (0, 255, 255), 2)
                    # Label
                    cv2.putText(annotated_frame, "ENTRY", (entry_x - 40, y2 - 30), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
                    cv2.putText(annotated_frame, "ZONE", (entry_x - 35, y2 - 10), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 255), 2)
                
                elif args.direction == 'right_to_left':
                    # Vertical lines: Entry zone on RIGHT side
                    entry_x = x1 + entry_margin
                    cv2.line(annotated_frame, (entry_x, y1), (entry_x, y2), (0, 255, 255), 2)
                    # Label
                    cv2.putText(annotated_frame, "ENTRY", (entry_x + 10, y2 - 30), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
                    cv2.putText(annotated_frame, "ZONE", (entry_x + 10, y2 - 10), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 255), 2)
                
                elif args.direction == 'top_to_bottom':
                    # Horizontal lines: Entry zone on TOP side
                    entry_y = y1 - entry_margin
                    cv2.line(annotated_frame, (x1, entry_y), (x2, entry_y), (0, 255, 255), 2)
                    # Label
                    cv2.putText(annotated_frame, "ENTRY ZONE", (x1 + 20, entry_y - 10), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
                
                elif args.direction == 'bottom_to_top':
                    # Horizontal lines: Entry zone on BOTTOM side
                    entry_y = y1 + entry_margin
                    cv2.line(annotated_frame, (x1, entry_y), (x2, entry_y), (0, 255, 255), 2)
                    # Label
                    cv2.putText(annotated_frame, "ENTRY ZONE", (x1 + 20, entry_y + 30), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
                
                # Add direction arrow on counting line
                direction_labels = {
                    'left_to_right': ('LEFT', 'RIGHT'),
                    'right_to_left': ('RIGHT', 'LEFT'),
                    'top_to_bottom': ('TOP', 'BOTTOM'),
                    'bottom_to_top': ('BOTTOM', 'TOP')
                }
                
                if args.direction in direction_labels:
                    # Label counting line
                    cv2.putText(annotated_frame, "COUNT LINE", 
                               (x1 + 10 if args.direction.endswith('bottom') or args.direction.endswith('top') else x1 + 10, 
                                30 if args.direction.endswith('bottom') or args.direction.endswith('top') else y1 + 30), 
                               cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 0), 2)
                
                # Add statistics overlay box
                total_crossings = sum(line_crossing_by_class.values())
                tracked_in_entry = len(entry_zone_ids)
                
                # Background rectangle for statistics
                cv2.rectangle(annotated_frame, (10, 10), (450, 100), (0, 0, 0), -1)
                cv2.rectangle(annotated_frame, (10, 10), (450, 100), (0, 255, 0), 3)
                
                # Statistics text
                cv2.putText(annotated_frame, f"COUNTED: {total_crossings}", 
                           (20, 40), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 255, 0), 2)
                cv2.putText(annotated_frame, f"In Entry Zone: {tracked_in_entry}", 
                           (20, 70), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 255), 2)
            
            # Apply privacy blur if enabled (outside line crossing block)
            if args.blur and face_cascade is not None:
                annotated_frame, faces, plates = apply_privacy_blur(annotated_frame, face_cascade)
                total_faces_blurred += faces
                total_plates_blurred += plates
            
            # Store processed frame
            processed_frames.append(annotated_frame)
            
            # Report progress every 30 frames
            if frame_count % 30 == 0:
                print(f"   Frame {frame_count}: {detections} detections", file=sys.stderr)
                if enable_line_crossing:
                    total_crossings = sum(line_crossing_by_class.values())
                    print(f"   Line crossings so far: {total_crossings}", file=sys.stderr)
        
        # Calculate final statistics
        total_detections = sum(detection_counts)
        avg_detections = total_detections / frame_count if frame_count > 0 else 0
        
        # Set class_counts based on line crossing mode
        if enable_line_crossing:
            # When line crossing is enabled, use line crossing counts ONLY
            class_counts = line_crossing_by_class.copy()
        else:
            # When no line crossing, count unique tracked IDs per class
            class_counts = {
                class_name: len(ids) 
                for class_name, ids in unique_ids_per_class.items()
            }
        
        # Build traffic timeline
        traffic_timeline = [
            {"minute": minute, "count": count} 
            for minute, count in sorted(per_minute_counts.items())
        ]
        
        # Find peak traffic minute
        peak_minute = max(per_minute_counts, key=per_minute_counts.get) if per_minute_counts else 0
        peak_count = per_minute_counts.get(peak_minute, 0)
        
        print(f"[INFO] Processed {frame_count} frames", file=sys.stderr)
        print(f"[INFO] Total detections: {total_detections}", file=sys.stderr)
        print(f"[INFO] Average detections per frame: {avg_detections:.2f}", file=sys.stderr)
        print(f"[INFO] Unique classes detected: {len(class_counts)}", file=sys.stderr)
        
        # Print line crossing statistics
        if enable_line_crossing:
            total_line_crossings = sum(line_crossing_by_class.values())
            print(f"[INFO] Line crossing detection:", file=sys.stderr)
            print(f"   Direction: {args.direction}", file=sys.stderr)
            print(f"   Total crossings: {total_line_crossings}", file=sys.stderr)
            print(f"   Unique IDs detected in entry zone: {len(entry_zone_ids)}", file=sys.stderr)
            print(f"   Unique IDs that crossed line: {len(crossed_ids)}", file=sys.stderr)
            print(f"   Total IDs tracked: {len(tracked_objects)}", file=sys.stderr)
            print(f"   Crossings by class:", file=sys.stderr)
            for class_name, count in sorted(line_crossing_by_class.items(), key=lambda x: x[1], reverse=True):
                print(f"      {class_name}: {count}", file=sys.stderr)
        
        # Print privacy blur statistics
        if args.blur:
            print(f"[INFO] Privacy blur applied:", file=sys.stderr)
            print(f"   Faces blurred: {total_faces_blurred}", file=sys.stderr)
            print(f"   License plates blurred: {total_plates_blurred}", file=sys.stderr)
        
        # Print class breakdown
        print(f"[INFO] Total detections by class:", file=sys.stderr)
        for class_name, count in sorted(class_counts.items(), key=lambda x: x[1], reverse=True):
            print(f"   {class_name}: {count}", file=sys.stderr)
        
        # Output structured statistics for parsing
        stats = {
            'total_frames': frame_count,
            'processed_frames': frame_count,
            'total_detections': total_detections,
            'average_detections_per_frame': round(avg_detections, 2),
            'class_counts': class_counts,
            'traffic_timeline': traffic_timeline,
            'peak_minute': peak_minute,
            'peak_count': peak_count,
            'line_crossing_count': sum(line_crossing_by_class.values()) if enable_line_crossing else None,
            'line_crossing_direction': args.direction if enable_line_crossing else None,
            'line_crossing_by_class': line_crossing_by_class if enable_line_crossing else None,
        }
        print(f"[STATS] {json.dumps(stats)}", file=sys.stderr)
        
        # Handle output video
        # Always save processed frames since we're always tracking now
        if processed_frames:
            # Save processed frames as video (tracking or blur or both)
            print(f"[INFO] Saving video with processed frames", file=sys.stderr)
            
            # Get video properties from first frame
            height, width = processed_frames[0].shape[:2]
            
            # Create video writer
            fourcc = cv2.VideoWriter_fourcc(*'mp4v')
            out = cv2.VideoWriter(args.output, fourcc, fps, (width, height))
            
            # Write all frames
            for frame in processed_frames:
                out.write(frame)
            
            out.release()
            print(f"[INFO] Saved {len(processed_frames)} processed frames", file=sys.stderr)
        else:
            # Move YOLO's output video (standard detection)
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

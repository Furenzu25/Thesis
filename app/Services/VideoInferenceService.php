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
    
    public function processVideo(Video $video): bool
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

            // Run inference
            $this->runInference($originalPath, $processedPath);

            // Verify output was created
            if (!file_exists($processedPath)) {
                throw new \Exception("Processed video was not created");
            }

            // Update video record
            $video->update([
                'processed_path' => $processedFilename,
                'status' => 'completed',
                'processing_duration' => time() - $startTime,
                'error_message' => null,
            ]);

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

    private function runInference(string $inputPath, string $outputPath): void
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

        // Run Python inference
        $command = sprintf(
            'cd %s && %s %s --model %s --source %s --output %s 2>&1',
            escapeshellarg($projectPath),
            escapeshellarg($condaPath),
            escapeshellarg($scriptPath),
            escapeshellarg($this->modelPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        Log::info('Running inference command', [
            'command' => $command,
            'working_dir' => $projectPath,
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

        // Clean up script
        @unlink($scriptPath);
    }

    private function getModelPath(): string
    {
        // Try custom model paths in order of preference
        $possiblePaths = [
            '/Users/jfrtenebroso/Developer/Thesis-Yolov8/best.pt',
            '/Users/jfrtenebroso/Developer/Thesis/2025-10-18_stage6_final/weights/best.pt',
            '/Users/jfrtenebroso/Developer/LaravelDevelopment/Thesis/2025-10-18_stage6_final/weights/best.pt',
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
import sys
import os
import argparse
from pathlib import Path
from ultralytics import YOLO

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--model', required=True, help='Path to model file')
    parser.add_argument('--source', required=True, help='Path to input video')
    parser.add_argument('--output', required=True, help='Path to output video')
    args = parser.parse_args()

    print(f"[INFO] Python version: {sys.version}", file=sys.stderr)
    print(f"[INFO] Working directory: {os.getcwd()}", file=sys.stderr)
    print(f"[INFO] Loading model from: {args.model}", file=sys.stderr)
    
    # Check if it's a built-in YOLO model or custom model file
    builtin_models = [
        'yolov8n.pt', 'yolov8s.pt', 'yolov8m.pt', 'yolov8l.pt', 'yolov8x.pt',
        'yolov8n-cls.pt', 'yolov8s-cls.pt', 'yolov8m-cls.pt', 'yolov8l-cls.pt', 'yolov8x-cls.pt',
        'yolov8n-seg.pt', 'yolov8s-seg.pt', 'yolov8m-seg.pt', 'yolov8l-seg.pt', 'yolov8x-seg.pt',
    ]
    
    if os.path.basename(args.model) not in builtin_models and not os.path.exists(args.model):
        print(f"[ERROR] Model not found at {args.model}", file=sys.stderr)
        sys.exit(1)
    
    if os.path.basename(args.model) in builtin_models:
        print(f"[INFO] Using built-in YOLO model: {args.model}", file=sys.stderr)
    else:
        print(f"[INFO] Using custom model file: {args.model}", file=sys.stderr)
        
    model = YOLO(args.model)
    print(f"[INFO] Model loaded successfully!", file=sys.stderr)
    
    print(f"[INFO] Processing video: {args.source}", file=sys.stderr)
    
    # Ensure output directory exists
    output_dir = os.path.dirname(args.output)
    os.makedirs(output_dir, exist_ok=True)
    print(f"[INFO] Output directory: {output_dir}", file=sys.stderr)
    
    # Create a temporary directory for YOLO output
    import tempfile
    temp_dir = tempfile.mkdtemp()
    print(f"[INFO] Temp directory: {temp_dir}", file=sys.stderr)
    
    try:
        # Run inference - YOLO will save to temp_dir/predict
        results = model.predict(
            source=args.source,
            save=True,
            project=temp_dir,
            name='predict',
            exist_ok=True,
            conf=0.25,
            iou=0.45,
            show_labels=True,
            show_conf=True,
        )
        
        print(f"[INFO] Inference complete!", file=sys.stderr)
        
        # Find the output video in temp directory
        predict_dir = Path(temp_dir) / 'predict'
        print(f"[INFO] Looking for output in: {predict_dir}", file=sys.stderr)
        
        if predict_dir.exists():
            # Find video files
            video_files = (
                list(predict_dir.glob('*.mp4')) + 
                list(predict_dir.glob('*.avi')) +
                list(predict_dir.glob('*.mov'))
            )
            
            if video_files:
                import shutil
                source_video = str(video_files[0])
                print(f"[INFO] Moving {source_video} to {args.output}", file=sys.stderr)
                shutil.move(source_video, args.output)
                print(f"[SUCCESS] Output saved to {args.output}", file=sys.stderr)
                
                # Clean up temp directory
                shutil.rmtree(temp_dir, ignore_errors=True)
                sys.exit(0)
            else:
                print(f"[ERROR] No video files found in {predict_dir}", file=sys.stderr)
                print(f"[ERROR] Directory contents: {list(predict_dir.iterdir())}", file=sys.stderr)
                sys.exit(1)
        else:
            print(f"[ERROR] Predict directory not created: {predict_dir}", file=sys.stderr)
            sys.exit(1)
            
    except Exception as e:
        print(f"[ERROR] Exception: {str(e)}", file=sys.stderr)
        import traceback
        traceback.print_exc(file=sys.stderr)
        sys.exit(1)
    finally:
        # Clean up temp directory
        import shutil
        if os.path.exists(temp_dir):
            shutil.rmtree(temp_dir, ignore_errors=True)

if __name__ == '__main__':
    main()
PYTHON;
    }
}

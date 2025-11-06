#!/usr/bin/env python3
"""
Standalone test script for YOLO inference on Mac M4
Tests model performance on a specific video file
"""
import sys
import os
from pathlib import Path
from ultralytics import YOLO
import shutil
import tempfile
from datetime import datetime


# Configuration
MODEL_PATH = "/Users/jfrtenebroso/Developer/Thesis/2025-10-18_stage6_final/weights/best.pt"
VIDEO_PATH = "/Users/jfrtenebroso/Downloads/Allvideos/Matina_monday/7-8am/5min-video.mp4"
OUTPUT_DIR = "/Users/jfrtenebroso/Developer/LaravelDevelopment/Thesis/storage/app/public/processed"


def main():
    print("="*70)
    print("🚗 YOLO INFERENCE TEST - Mac M4 Optimized")
    print("="*70)
    
    # Check video exists
    if not os.path.exists(VIDEO_PATH):
        print(f"❌ ERROR: Video not found at: {VIDEO_PATH}")
        print(f"Please check the path and try again.")
        sys.exit(1)
    
    video_size = os.path.getsize(VIDEO_PATH) / (1024 * 1024)
    print(f"\n📹 Input Video:")
    print(f"   Path: {VIDEO_PATH}")
    print(f"   Size: {video_size:.1f} MB")
    
    # Check model exists
    if not os.path.exists(MODEL_PATH):
        print(f"\n❌ ERROR: Model not found at: {MODEL_PATH}")
        print(f"Please check the model path.")
        sys.exit(1)
    
    model_size = os.path.getsize(MODEL_PATH) / (1024 * 1024)
    print(f"\n🤖 Model:")
    print(f"   Path: {MODEL_PATH}")
    print(f"   Size: {model_size:.1f} MB")
    
    # Detect device
    device = 'cpu'
    try:
        import torch
        if torch.backends.mps.is_available():
            device = 'mps'
            print(f"\n⚡ Using MPS (Metal Performance Shaders) - Mac GPU acceleration")
        else:
            print(f"\n⚠️  MPS not available, using CPU")
    except Exception as e:
        print(f"\n⚠️  Could not detect MPS, using CPU: {str(e)}")
    
    # Load model
    print(f"\n🔄 Loading model...")
    try:
        model = YOLO(MODEL_PATH)
        print(f"✅ Model loaded successfully!")
    except Exception as e:
        print(f"❌ Failed to load model: {str(e)}")
        sys.exit(1)
    
    # Prepare output
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output_filename = f"test_inference_{timestamp}.mp4"
    output_path = os.path.join(OUTPUT_DIR, output_filename)
    
    temp_dir = tempfile.mkdtemp()
    print(f"\n🎬 Processing video...")
    print(f"   Device: {device.upper()}")
    print(f"   Confidence: 0.25")
    print(f"   Image size: 832")
    
    try:
        import time
        start_time = time.time()
        
        # Run inference
        results = model.predict(
            source=VIDEO_PATH,
            device=device,
            imgsz=832,
            conf=0.25,
            iou=0.45,
            save=True,
            show_labels=True,
            show_conf=True,
            show=False,
            stream=True,
            project=temp_dir,
            name='predict',
            exist_ok=True,
            verbose=False,
        )
        
        # Process frames
        frame_count = 0
        total_detections = 0
        
        print(f"\n📊 Processing frames:")
        for result in results:
            frame_count += 1
            if result.boxes is not None:
                detections = len(result.boxes)
                total_detections += detections
            else:
                detections = 0
                
            if frame_count % 30 == 0:
                avg_detections = total_detections / frame_count
                print(f"   Frame {frame_count}: {detections} detections (avg: {avg_detections:.1f})")
        
        processing_time = time.time() - start_time
        fps = frame_count / processing_time if processing_time > 0 else 0
        
        print(f"\n✅ Inference complete!")
        print(f"   Total frames: {frame_count}")
        print(f"   Total detections: {total_detections}")
        print(f"   Average detections/frame: {total_detections/frame_count:.1f}")
        print(f"   Processing time: {processing_time:.1f}s")
        print(f"   Processing speed: {fps:.1f} FPS")
        
        # Move output video
        predict_dir = Path(temp_dir) / 'predict'
        if not predict_dir.exists():
            print(f"\n❌ ERROR: Output directory not created")
            sys.exit(1)
        
        video_files = list(predict_dir.glob('*.mp4')) + list(predict_dir.glob('*.avi'))
        if not video_files:
            print(f"\n❌ ERROR: No output video found")
            sys.exit(1)
        
        shutil.move(str(video_files[0]), output_path)
        
        output_size = os.path.getsize(output_path) / (1024 * 1024)
        print(f"\n📹 Output Video:")
        print(f"   Path: {output_path}")
        print(f"   Size: {output_size:.1f} MB")
        
        print(f"\n{'='*70}")
        print(f"🎉 SUCCESS! Model test completed")
        print(f"{'='*70}")
        
    except Exception as e:
        print(f"\n❌ ERROR: {str(e)}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
    finally:
        if os.path.exists(temp_dir):
            shutil.rmtree(temp_dir, ignore_errors=True)


if __name__ == '__main__':
    main()


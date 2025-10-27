"""
Vehicle Counting with Line Crossing Detection

This script uses LINE CROSSING to count vehicles - the industry standard approach.
Only counts vehicles when they cross a virtual line (enter or exit the frame).

This solves:
- ✅ No counting static/parked vehicles
- ✅ No double counting
- ✅ Accurate traffic flow measurement
- ✅ Industry-standard methodology

Perfect for thesis: "We implemented line-crossing vehicle counting,
which is the standard approach used in traffic monitoring systems."

Usage:
    python scripts/6_inference/count_vehicles_line_crossing.py
"""

import cv2
import numpy as np
from pathlib import Path
from ultralytics import YOLO
from collections import defaultdict, OrderedDict
from datetime import datetime
import json


# ============================================================
# CONFIGURATION
# ============================================================
PROJECT_ROOT = Path(r"A:\OPENCV\Thesis-Yolov8")
RUNS_DIR = PROJECT_ROOT / "runs"
VIDEOS_DIR = PROJECT_ROOT / "Videos"
OUTPUT_DIR = PROJECT_ROOT / "outputs" / "line_crossing"

# Best model
BEST_MODEL = RUNS_DIR / "2025-10-18_stage6_final" / "weights" / "best.pt"

# Input video
INPUT_VIDEO = VIDEOS_DIR / "MacArthur_North_5min_clip.mkv"

# Vehicle classes
VEHICLE_CLASSES = [
    'sedan', 'bus', 'motorcycle', 'jeepney(sarao)', 'truck', 'medium_sized',
    'jeepney(uso-uso)', 'jeepney(multicab)', 'van', 'autorickshaw',
    'tricycle', 'compact vehicle'
]

# Colors for each class (BGR)
CLASS_COLORS = {
    0: (255, 0, 0),      # sedan - Blue
    1: (0, 255, 255),    # bus - Yellow
    2: (0, 165, 255),    # motorcycle - Orange
    3: (147, 20, 255),   # jeepney(sarao) - Pink
    4: (0, 255, 0),      # truck - Green
    5: (255, 255, 0),    # medium_sized - Cyan
    6: (255, 0, 255),    # jeepney(uso-uso) - Magenta
    7: (128, 0, 128),    # jeepney(multicab) - Purple
    8: (0, 128, 255),    # van - Light Blue
    9: (255, 128, 0),    # autorickshaw - Orange-Blue
    10: (128, 255, 0),   # tricycle - Light Green
    11: (255, 0, 128),   # compact vehicle - Pink-Blue
}

# Detection settings
CONFIDENCE_THRESHOLD = 0.4
MAX_DISAPPEARED = 10  # Reduced from 30 - remove ghost detections faster (10 frames = ~0.4 seconds)

# Counting line position
# For traffic moving left-to-right or right-to-left: vertical line
# For traffic moving top-to-bottom or bottom-to-top: horizontal line
LINE_POSITION = 0.75  # Position as percentage (0.75 = 75% from left, works for any resolution)
LINE_TYPE = 'vertical'  # 'horizontal' or 'vertical'
USE_ABSOLUTE_POSITION = False  # Use percentage (safer for different video resolutions)

# Alternative: Use absolute position (uncomment if you know exact video resolution)
# LINE_POSITION = 1811  # Absolute pixel position (only for 1920x1080 videos)
# USE_ABSOLUTE_POSITION = True


# ============================================================
# LINE CROSSING TRACKER
# ============================================================
class LineCrossingTracker:
    """
    Track vehicles and count when they cross a virtual line
    
    Industry standard approach:
    - Only count vehicles that cross the line
    - Track direction (entering/exiting)
    - Prevent double counting
    """
    
    def __init__(self, line_position, line_type='horizontal', max_disappeared=30, use_absolute=False):
        self.line_position = line_position
        self.line_type = line_type
        self.max_disappeared = max_disappeared
        self.use_absolute = use_absolute
        
        # Tracking
        self.next_object_id = 0
        self.objects = OrderedDict()  # ID -> (centroid, class_id, bbox)
        self.disappeared = OrderedDict()  # ID -> frame count
        self.previous_positions = OrderedDict()  # ID -> previous centroid
        
        # Counting
        self.counted_ids = set()  # IDs that have been counted
        self.total_counts = defaultdict(int)  # class_id -> count
        
        # Direction tracking (optional)
        self.direction_counts = {
            'forward': defaultdict(int),  # e.g., top-to-bottom or left-to-right
            'backward': defaultdict(int)  # e.g., bottom-to-top or right-to-left
        }
        
    def register(self, centroid, class_id, bbox):
        """Register new object"""
        self.objects[self.next_object_id] = (centroid, class_id, bbox)
        self.disappeared[self.next_object_id] = 0
        self.previous_positions[self.next_object_id] = centroid
        self.next_object_id += 1
        
    def deregister(self, object_id):
        """Remove disappeared object"""
        del self.objects[object_id]
        del self.disappeared[object_id]
        if object_id in self.previous_positions:
            del self.previous_positions[object_id]
            
    def check_line_crossing(self, object_id, current_centroid, class_id, frame_height, frame_width):
        """
        Check if object crossed the counting line
        
        Returns:
            'forward', 'backward', or None
        """
        if object_id not in self.previous_positions:
            return None
            
        if object_id in self.counted_ids:
            return None  # Already counted
            
        prev_centroid = self.previous_positions[object_id]
        
        if self.line_type == 'horizontal':
            # Horizontal line (for vertical traffic flow)
            line_y = int(self.line_position) if self.use_absolute else int(frame_height * self.line_position)
            prev_y = prev_centroid[1]
            curr_y = current_centroid[1]
            
            # Check if crossed from top to bottom (forward)
            if prev_y < line_y <= curr_y:
                self.counted_ids.add(object_id)
                self.total_counts[class_id] += 1
                self.direction_counts['forward'][class_id] += 1
                return 'forward'
            
            # Check if crossed from bottom to top (backward)
            elif prev_y > line_y >= curr_y:
                self.counted_ids.add(object_id)
                self.total_counts[class_id] += 1
                self.direction_counts['backward'][class_id] += 1
                return 'backward'
                
        elif self.line_type == 'vertical':
            # Vertical line (for horizontal traffic flow)
            line_x = int(self.line_position) if self.use_absolute else int(frame_width * self.line_position)
            prev_x = prev_centroid[0]
            curr_x = current_centroid[0]
            
            # Check if crossed from left to right (forward)
            if prev_x < line_x <= curr_x:
                self.counted_ids.add(object_id)
                self.total_counts[class_id] += 1
                self.direction_counts['forward'][class_id] += 1
                return 'forward'
            
            # Check if crossed from right to left (backward)
            elif prev_x > line_x >= curr_x:
                self.counted_ids.add(object_id)
                self.total_counts[class_id] += 1
                self.direction_counts['backward'][class_id] += 1
                return 'backward'
        
        return None
        
    def update(self, detections, frame_height, frame_width):
        """
        Update tracker with new detections
        
        Args:
            detections: List of (centroid, class_id, bbox)
            frame_height: Frame height for line position
            frame_width: Frame width for line position
        """
        # Store crossing events
        crossing_events = []
        
        # If no detections, increment disappeared counter
        if len(detections) == 0:
            for object_id in list(self.disappeared.keys()):
                self.disappeared[object_id] += 1
                if self.disappeared[object_id] > self.max_disappeared:
                    self.deregister(object_id)
            return self.objects, crossing_events
        
        # If no existing objects, register all
        if len(self.objects) == 0:
            for centroid, class_id, bbox in detections:
                self.register(centroid, class_id, bbox)
        else:
            # Match detections to existing objects (simple distance-based)
            object_ids = list(self.objects.keys())
            object_centroids = [self.objects[oid][0] for oid in object_ids]
            
            # Compute distances
            D = np.zeros((len(object_centroids), len(detections)))
            for i, obj_centroid in enumerate(object_centroids):
                for j, (det_centroid, _, _) in enumerate(detections):
                    D[i, j] = np.linalg.norm(
                        np.array(obj_centroid) - np.array(det_centroid)
                    )
            
            # Match using greedy approach
            rows = D.min(axis=1).argsort()
            cols = D.argmin(axis=1)[rows]
            
            used_rows = set()
            used_cols = set()
            
            # Update matched objects and check for line crossing
            for row, col in zip(rows, cols):
                if row in used_rows or col in used_cols:
                    continue
                    
                if D[row, col] > 150:  # Max distance threshold
                    continue
                    
                object_id = object_ids[row]
                centroid, class_id, bbox = detections[col]
                
                # Check for line crossing BEFORE updating position
                direction = self.check_line_crossing(
                    object_id, centroid, class_id, frame_height, frame_width
                )
                
                if direction:
                    crossing_events.append({
                        'id': object_id,
                        'class_id': class_id,
                        'direction': direction,
                        'position': centroid
                    })
                
                # Update object
                self.previous_positions[object_id] = self.objects[object_id][0]
                self.objects[object_id] = (centroid, class_id, bbox)
                self.disappeared[object_id] = 0
                
                used_rows.add(row)
                used_cols.add(col)
            
            # Register new objects (unmatched detections)
            for col, (centroid, class_id, bbox) in enumerate(detections):
                if col not in used_cols:
                    self.register(centroid, class_id, bbox)
            
            # Mark disappeared objects (unmatched existing)
            for row, object_id in enumerate(object_ids):
                if row not in used_rows:
                    self.disappeared[object_id] += 1
                    if self.disappeared[object_id] > self.max_disappeared:
                        self.deregister(object_id)
            
            # ADDITIONAL: Remove objects that are far outside frame boundaries
            # This prevents ghost detections from lingering
            for object_id in list(self.objects.keys()):
                centroid, _, bbox = self.objects[object_id]
                x_center, y_center = centroid
                
                # If object is far outside frame (with margin), remove it
                margin = 200  # pixels
                if (x_center < -margin or x_center > frame_width + margin or
                    y_center < -margin or y_center > frame_height + margin):
                    self.deregister(object_id)
        
        return self.objects, crossing_events


# ============================================================
# VISUALIZATION
# ============================================================
def draw_counting_line(frame, line_position, line_type='horizontal', use_absolute=False):
    """Draw the counting line on frame"""
    h, w = frame.shape[:2]
    
    # Line color and thickness - BRIGHT and THICK for visibility
    line_color = (0, 255, 0)  # Bright Green
    thickness = 5  # Thicker line for better visibility
    
    if line_type == 'horizontal':
        y = int(line_position) if use_absolute else int(h * line_position)
        cv2.line(frame, (0, y), (w, y), line_color, thickness)
        
        # Label
        cv2.putText(
            frame,
            "COUNTING LINE",
            (10, y - 10),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.7,
            line_color,
            2
        )
    else:  # vertical
        x = int(line_position) if use_absolute else int(w * line_position)
        
        # Draw semi-transparent background for line visibility
        overlay = frame.copy()
        cv2.line(overlay, (x, 0), (x, h), line_color, thickness + 10)
        cv2.addWeighted(overlay, 0.3, frame, 0.7, 0, frame)
        
        # Draw main line (solid and bright)
        cv2.line(frame, (x, 0), (x, h), line_color, thickness)
        
        # Label with background for visibility
        label_text = "COUNTING LINE"
        (label_w, label_h), _ = cv2.getTextSize(label_text, cv2.FONT_HERSHEY_SIMPLEX, 0.7, 2)
        label_x = x - 180
        label_y = 30
        
        # Label background (dark)
        cv2.rectangle(frame, (label_x - 5, label_y - label_h - 5), 
                     (label_x + label_w + 5, label_y + 5), (0, 0, 0), -1)
        
        # Label text (bright green)
        cv2.putText(
            frame,
            label_text,
            (label_x, label_y),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.7,
            line_color,
            2
        )
    
    return frame


def draw_detections_with_tracking(frame, objects, crossing_events):
    """Draw bounding boxes and track IDs"""
    for object_id, (centroid, class_id, bbox) in objects.items():
        x1, y1, x2, y2 = map(int, bbox)
        
        # Color
        color = CLASS_COLORS.get(class_id, (255, 255, 255))
        
        # Check if this object just crossed
        just_crossed = any(e['id'] == object_id for e in crossing_events)
        if just_crossed:
            color = (0, 255, 0)  # Green for crossing
            thickness = 4
        else:
            thickness = 2
        
        # Draw bbox
        cv2.rectangle(frame, (x1, y1), (x2, y2), color, thickness)
        
        # Draw centroid
        cx, cy = map(int, centroid)
        cv2.circle(frame, (cx, cy), 4, color, -1)
        
        # Label
        class_name = VEHICLE_CLASSES[class_id]
        label = f"ID{object_id}: {class_name}"
        
        # Add "COUNTED!" if just crossed
        if just_crossed:
            label += " [COUNTED!]"
        
        # Label background
        (label_w, label_h), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.5, 2)
        cv2.rectangle(
            frame,
            (x1, y1 - label_h - 10),
            (x1 + label_w + 10, y1),
            color,
            -1
        )
        
        # Label text
        cv2.putText(
            frame,
            label,
            (x1 + 5, y1 - 5),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.5,
            (255, 255, 255),
            2
        )
    
    return frame


def draw_statistics_panel(frame, tracker, frame_num, total_frames, fps):
    """Draw statistics panel - BOTTOM LEFT with minimal overlay"""
    h, w = frame.shape[:2]
    
    # Calculate panel height dynamically based on content
    total_counted = sum(tracker.total_counts.values())
    num_classes_detected = sum(1 for count in tracker.total_counts.values() if count > 0)
    
    # Base height + class lines
    base_height = 160  # Frame info + headers
    class_height = num_classes_detected * 22  # 22px per class
    panel_height = base_height + class_height + 40  # Extra padding
    
    panel_width = 380
    
    # Position at BOTTOM-LEFT
    panel_x_start = 0
    panel_y_start = h - panel_height
    
    # Draw minimal dark overlay (semi-transparent)
    overlay = frame.copy()
    cv2.rectangle(overlay, (panel_x_start, panel_y_start), (panel_width, h), (0, 0, 0), -1)
    alpha = 0.5  # More transparent
    frame = cv2.addWeighted(overlay, alpha, frame, 1 - alpha, 0)
    
    # Text settings
    font = cv2.FONT_HERSHEY_SIMPLEX
    x_offset = 10
    y_offset = panel_y_start + 25
    
    # Title - compact
    cv2.putText(frame, "LINE CROSSING COUNTER", (x_offset, y_offset),
                font, 0.6, (0, 255, 255), 2)
    y_offset += 30
    
    # Video info - compact
    cv2.putText(frame, f"Frame: {frame_num}/{total_frames}  FPS: {fps:.1f}", (x_offset, y_offset),
                font, 0.45, (255, 255, 255), 1)
    y_offset += 25
    
    # Current on screen - compact
    current_count = len(tracker.objects)
    cv2.putText(frame, f"ON SCREEN NOW: {current_count}", (x_offset, y_offset),
                font, 0.5, (255, 255, 0), 1)
    y_offset += 30
    
    # Total counted (crossed line)
    cv2.putText(frame, "VEHICLES COUNTED:", (x_offset, y_offset),
                font, 0.6, (0, 255, 0), 2)
    y_offset += 25
    
    cv2.putText(frame, f"Total: {total_counted}", (x_offset, y_offset),
                font, 0.55, (0, 255, 0), 2)
    y_offset += 25
    
    # Per-class counts - compact
    for class_id in sorted(tracker.total_counts.keys()):
        if tracker.total_counts[class_id] > 0:
            class_name = VEHICLE_CLASSES[class_id]
            count = tracker.total_counts[class_id]
            class_color = CLASS_COLORS.get(class_id, (255, 255, 255))
            
            cv2.putText(frame, f"{class_name}: {count}", (x_offset + 5, y_offset),
                        font, 0.4, class_color, 1)
            y_offset += 22
    
    # Method info - very bottom
    cv2.putText(frame, "Method: Line Crossing (Industry Standard)", (x_offset, h - 10),
                font, 0.35, (180, 180, 180), 1)
    
    return frame


# ============================================================
# MAIN PROCESSING
# ============================================================
def process_video(video_path, model, output_path, line_position, line_type, use_absolute=False):
    """Process video with line crossing detection"""
    cap = cv2.VideoCapture(str(video_path))
    
    if not cap.isOpened():
        print(f"❌ Could not open video: {video_path}")
        return None
    
    # Video properties
    fps = int(cap.get(cv2.CAP_PROP_FPS))
    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    
    print(f"\n📹 Video Properties:")
    print(f"   Resolution: {width}x{height}")
    print(f"   FPS: {fps}")
    print(f"   Total Frames: {total_frames}")
    print(f"   Duration: {total_frames/fps:.1f} seconds")
    print(f"\n📏 Counting Line:")
    print(f"   Type: {line_type}")
    if use_absolute:
        actual_pos = int(line_position)
        print(f"   Position: {'x' if line_type=='vertical' else 'y'}={actual_pos} pixels (absolute)")
    else:
        actual_pos = int(width * line_position) if line_type == 'vertical' else int(height * line_position)
        print(f"   Position: {line_position*100:.0f}% from {'top' if line_type=='horizontal' else 'left'}")
        print(f"   Actual pixel position: {'x' if line_type=='vertical' else 'y'}={actual_pos} pixels")
    
    # Warning if line is outside frame
    if line_type == 'vertical' and actual_pos >= width:
        print(f"   ⚠️  WARNING: Line position ({actual_pos}px) is OUTSIDE video width ({width}px)!")
        print(f"   ⚠️  No vehicles will be counted! Adjust LINE_POSITION.")
    elif line_type == 'horizontal' and actual_pos >= height:
        print(f"   ⚠️  WARNING: Line position ({actual_pos}px) is OUTSIDE video height ({height}px)!")
        print(f"   ⚠️  No vehicles will be counted! Adjust LINE_POSITION.")
    
    # Output writer
    fourcc = cv2.VideoWriter_fourcc(*'mp4v')
    out = cv2.VideoWriter(str(output_path), fourcc, fps, (width, height))
    
    # Initialize tracker
    tracker = LineCrossingTracker(line_position, line_type, MAX_DISAPPEARED, use_absolute)
    
    frame_count = 0
    all_crossing_events = []
    
    print(f"\n⏳ Processing video...")
    
    import time
    start_time = time.time()
    
    while True:
        ret, frame = cap.read()
        if not ret:
            break
        
        frame_count += 1
        
        # Run detection
        results = model(frame, conf=CONFIDENCE_THRESHOLD, verbose=False)
        
        # Extract detections
        detections = []
        
        if results[0].boxes is not None:
            boxes = results[0].boxes.xyxy.cpu().numpy()
            confidences = results[0].boxes.conf.cpu().numpy()
            class_ids = results[0].boxes.cls.cpu().numpy().astype(int)
            
            for box, conf, cls_id in zip(boxes, confidences, class_ids):
                x1, y1, x2, y2 = box
                cx = int((x1 + x2) / 2)
                cy = int((y1 + y2) / 2)
                
                detections.append(((cx, cy), cls_id, box))
        
        # Update tracker and get crossing events
        objects, crossing_events = tracker.update(detections, height, width)
        
        # Store crossing events
        for event in crossing_events:
            all_crossing_events.append({
                'frame': frame_count,
                **event,
                'class_name': VEHICLE_CLASSES[event['class_id']]
            })
        
        # Draw counting line
        frame = draw_counting_line(frame, line_position, line_type, use_absolute)
        
        # Draw detections and tracking
        frame = draw_detections_with_tracking(frame, objects, crossing_events)
        
        # Draw statistics
        elapsed = time.time() - start_time
        current_fps = frame_count / elapsed if elapsed > 0 else fps
        frame = draw_statistics_panel(frame, tracker, frame_count, total_frames, current_fps)
        
        # Write frame
        out.write(frame)
        
        if frame_count % 30 == 0:
            progress = (frame_count / total_frames) * 100
            print(f"   Progress: {frame_count}/{total_frames} ({progress:.1f}%)")
    
    cap.release()
    out.release()
    
    processing_time = time.time() - start_time
    
    return {
        'total_frames': frame_count,
        'processing_time': processing_time,
        'avg_fps': frame_count / processing_time,
        'crossing_events': all_crossing_events,
        'final_counts': dict(tracker.total_counts),
        'final_total': sum(tracker.total_counts.values()),
        'direction_counts': {
            'forward': dict(tracker.direction_counts['forward']),
            'backward': dict(tracker.direction_counts['backward'])
        }
    }


def save_statistics(stats, output_dir):
    """Save statistics"""
    # Convert numpy types
    def convert_types(obj):
        if isinstance(obj, dict):
            return {str(k): convert_types(v) for k, v in obj.items()}
        elif isinstance(obj, list):
            return [convert_types(item) for item in obj]
        elif isinstance(obj, np.integer):
            return int(obj)
        elif isinstance(obj, np.floating):
            return float(obj)
        else:
            return obj
    
    stats_converted = convert_types(stats)
    
    # JSON
    json_path = output_dir / "line_crossing_statistics.json"
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(stats_converted, f, indent=2)
    
    print(f"\n📊 Statistics saved: {json_path}")
    
    # Summary
    summary_path = output_dir / "summary.txt"
    with open(summary_path, 'w', encoding='utf-8') as f:
        f.write("="*70 + "\n")
        f.write("LINE CROSSING VEHICLE COUNTING RESULTS\n")
        f.write("="*70 + "\n\n")
        
        f.write("Method: Line Crossing Detection (Industry Standard)\n")
        f.write("Model: Stage 6 Final (YOLOv8x)\n")
        f.write("mAP@50: 99.0%\n\n")
        
        f.write(f"Video: {stats['total_frames']} frames\n")
        f.write(f"Processing Time: {stats['processing_time']:.1f}s\n")
        f.write(f"Average FPS: {stats['avg_fps']:.1f}\n\n")
        
        f.write("="*70 + "\n")
        f.write("VEHICLES COUNTED (CROSSED LINE)\n")
        f.write("="*70 + "\n\n")
        
        f.write(f"TOTAL: {stats['final_total']} vehicles\n\n")
        
        f.write("Per Class:\n")
        for class_id in sorted(stats['final_counts'].keys()):
            class_name = VEHICLE_CLASSES[int(class_id)]
            count = stats['final_counts'][class_id]
            percentage = (count / stats['final_total'] * 100) if stats['final_total'] > 0 else 0
            f.write(f"  {class_name:20s}: {count:4d} ({percentage:5.1f}%)\n")
        
        f.write("\n" + "="*70 + "\n")
        f.write("WHY LINE CROSSING?\n")
        f.write("="*70 + "\n\n")
        f.write("✅ Only counts vehicles that pass through the scene\n")
        f.write("✅ Does NOT count static/parked vehicles\n")
        f.write("✅ Prevents double counting\n")
        f.write("✅ Industry standard for traffic monitoring\n")
        f.write("✅ More accurate than simple detection counting\n")
    
    print(f"📝 Summary saved: {summary_path}")


# ============================================================
# MAIN
# ============================================================
def main():
    """Main execution"""
    print("="*70)
    print("🚗 LINE CROSSING VEHICLE COUNTER")
    print("="*70)
    
    print(f"\n📋 Configuration:")
    print(f"  Input: {INPUT_VIDEO}")
    print(f"  Model: {BEST_MODEL}")
    print(f"  Output: {OUTPUT_DIR}")
    print(f"  Line Type: {LINE_TYPE}")
    if USE_ABSOLUTE_POSITION:
        print(f"  Line Position: {'x' if LINE_TYPE=='vertical' else 'y'}={int(LINE_POSITION)} pixels (absolute)")
    else:
        print(f"  Line Position: {LINE_POSITION*100:.0f}%")
    
    if not INPUT_VIDEO.exists():
        print(f"\n❌ ERROR: Video not found!")
        return
    
    if not BEST_MODEL.exists():
        print(f"\n❌ ERROR: Model not found!")
        return
    
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    
    # Load model
    print(f"\n🤖 Loading model...")
    model = YOLO(str(BEST_MODEL))
    print(f"✅ Model loaded: Stage 6 Final (99.0% mAP@50)")
    
    # Output path
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output_video = OUTPUT_DIR / f"line_crossing_{timestamp}.mp4"
    
    # Process
    stats = process_video(INPUT_VIDEO, model, output_video, LINE_POSITION, LINE_TYPE, USE_ABSOLUTE_POSITION)
    
    if stats is None:
        print("\n❌ Processing failed!")
        return
    
    # Save statistics
    save_statistics(stats, OUTPUT_DIR)
    
    # Print results
    print("\n" + "="*70)
    print("✅ PROCESSING COMPLETED!")
    print("="*70)
    
    print(f"\n📹 Output Video: {output_video}")
    print(f"⏱️  Processing Time: {stats['processing_time']:.1f} seconds")
    print(f"🎬 Average FPS: {stats['avg_fps']:.1f}")
    
    print(f"\n🚗 VEHICLES COUNTED (CROSSED LINE): {stats['final_total']}")
    print(f"\nPer Class:")
    for class_id in sorted(stats['final_counts'].keys()):
        class_name = VEHICLE_CLASSES[int(class_id)]
        count = stats['final_counts'][class_id]
        percentage = (count / stats['final_total'] * 100) if stats['final_total'] > 0 else 0
        print(f"  {class_name:20s}: {count:4d} ({percentage:5.1f}%)")
    
    print(f"\n✅ This counts ONLY vehicles that crossed the line")
    print(f"✅ Does NOT count static/parked vehicles")
    print(f"✅ Industry standard methodology")
    print("="*70)


if __name__ == "__main__":
    main()


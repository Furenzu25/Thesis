## Thesis Website Progress Report

### Project Title
Intelligent Video Inference Dashboard (Local YOLOv8-based Processing)

### Team / Author
J. F. R. Tenebroso et al.

### Date
2025-09-30

---

### 1. Executive Summary
This report documents the design and implementation progress of a local-first web system for uploading videos, running AI inference using a pre-trained YOLO model (`best.pt`), and presenting the processed outputs in an administrative dashboard. The system is built with Laravel 12.x, Filament v3 for the admin UI, Livewire for form handling, and a Python-based inference pipeline (Ultralytics YOLO) invoked from Laravel jobs.

Key achievements:
- Established end-to-end flow: upload → queue → Python inference → processed output → dashboard display.
- Implemented file storage for large (≤500 MB) local video uploads with correct PHP and Livewire configuration.
- Integrated a robust job pipeline with transparent logging, error handling, and status updates.
- Ensured reproducible local environment configuration, including conda integration for Python dependencies.

---

### 2. System Architecture

High-level flow:
1. User uploads a video via Filament Resource page.
2. Laravel stores the file to `storage/app/videos/uploads` (private disk).
3. A queue job dispatches to process the video.
4. Laravel invokes a Python script in a specific conda environment (`yolov8_m4`), loading `best.pt`.
5. YOLO runs inference and saves the processed video to `storage/app/public/processed`.
6. The dashboard displays both original and processed videos, plus status and metrics.

Core components:
- Laravel 12.x (PHP 8.4), MySQL (or SQLite for testing), Queue (database driver), Filament v3, Livewire.
- Storage disks: `videos` (private), `processed_videos` (public symlinked via `storage:link`).
- Background processing with `ProcessVideoJob` and `VideoInferenceService`.
- Python (conda env `yolov8_m4`), Ultralytics YOLO, OpenCV, Torch.

---

### 3. Implementation Details

#### 3.1 Backend (Laravel)
- Configured `config/filesystems.php` with `videos` and `processed_videos` disks.
- Migration `videos` table: `title`, `description`, `original_filename`, `original_path`, `processed_path`, `file_size`, `status`, `error_message`, `processing_duration`.
- `Video` model provides computed URLs for original and processed videos and convenience helpers.
- Queue configured via `config/queue.php` using database driver; `jobs` and `failed_jobs` tables present.

#### 3.2 Admin UI (Filament)
- `VideoResource` for CRUD with `FileUpload` component, 512 MB max, accepted video types, metadata population.
- Pages: `ListVideos`, `CreateVideo` (dispatches processing job), `EditVideo`, `ViewVideo` (shows playback of processed and original videos via a Blade infolist view).
- Dashboard widgets: `VideoStatsWidget` (counters) and `RecentVideosWidget` (latest uploads).

#### 3.3 Inference Pipeline
- `VideoInferenceService` writes a temporary Python script to `storage/app/inference_script.py` per job run.
- Uses conda environment detection or hardcoded path to run Python with Ultralytics YOLO.
- Saves YOLO outputs to a temporary directory, then moves the resulting video into `storage/app/public/processed` under a deterministic filename.
- Extensive logging, verification of input/output existence, and defensive error handling.

---

### 4. Environment & Configuration

#### 4.1 PHP / Laravel
- PHP INI for large uploads: `upload_max_filesize=500M`, `post_max_size=500M`, `max_execution_time=600`, `memory_limit=512M`.
- For local dev, `.user.ini` placed in `public/` and server launched with `-d` flags if needed.
- Livewire config updated (`config/livewire.php`) to allow 512 MB uploads and adequate timeouts.
- Symbolic link: `php artisan storage:link` to expose `storage/app/public` at `public/storage`.

#### 4.2 Python / Conda
- Conda environment: `yolov8_m4` with `ultralytics`, `torch`, `opencv-python` installed.
- Verified via `conda run -n yolov8_m4 python -c "from ultralytics import YOLO; print('OK')"`.
- Model path: `/Users/jfrtenebroso/Developer/Thesis/best.pt`.

#### 4.3 Local Servers
- Preferred: Laravel Valet or Herd for robust local development (bypasses built-in server limitations).
- Alternative: `composer dev` (server + queue + logs + vite) after `npm install`, or manually run:
  - `php artisan serve`
  - `php artisan queue:work --tries=1 --timeout=900`
  - `php artisan pail` (optional log viewer)

---

### 5. Key Issues Encountered & Resolutions

1) File upload limit (12 MB) blocked larger videos
- Cause: PHP and Livewire limits.
- Fix: Updated php.ini (or server flags) and `config/livewire.php` to 512 MB; verified via `ini_get` and Livewire config.

2) `.env` and storage symlink
- Cause: Missing `.env` or symlink for public storage.
- Fix: Ensure `.env` exists; run `php artisan storage:link`.

3) Markdown remnants in PHP files
- Cause: Copy-paste included ``` fences into PHP.
- Fix: Removed stray code fences from `VideoResource.php`.

4) Missing DB columns (`description`)
- Cause: Migration not applied to MySQL.
- Fix: `php artisan migrate` or `migrate:fresh`; or additive migration for column.

5) Python `ultralytics` not found
- Cause: Wrong Python interpreter.
- Fix: Added conda path detection and `conda run -n yolov8_m4 ...` execution path.

6) YOLO output saved outside project
- Cause: Default `runs/detect/predict` under different CWD.
- Fix: Python script now uses a temp project dir and moves the final video to `storage/app/public/processed`.

7) Long error messages breaking DB updates
- Cause: DB field size vs. long stderr output.
- Fix: Truncate error messages before persisting; improved exception handling in job.

8) Built-in PHP server broken pipe on long requests
- Cause: `php -S` limitations during large uploads/inference.
- Fix: Recommend Valet/Herd; or run with increased limits; or use Nginx+PHP-FPM.

---

### 6. Testing & Verification

- Unit/Feature: Basic Laravel tests scaffolded; further tests planned for job dispatch and model attributes.
- Manual tests:
  - Upload small and medium-sized videos.
  - Verify queue logs and `laravel.log` for inference progress.
  - Confirm processed outputs appear in `storage/app/public/processed` and render in the View page.
  - Validate error scenarios: missing model, corrupted input, timeout.

---

### 7. Current Status & Metrics

- Upload → Process → Display flow works for validated inputs.
- Queue processing time (example): ~60–120 seconds for a ~30–40s video on local machine, depending on model and hardware.
- Dashboard widgets reflect counts of total, processed, processing, and failed videos.

---

### 8. Next Steps

- Add progress indicators and notifications for job completion (e.g., broadcast events, Filament notifications).
- Add retry and resumable inference options for transient failures.
- Expose model/config selection (confidence, IoU) via admin UI.
- Add pagination and preview thumbnails for videos.
- Implement role/permission controls if multi-user.
- Expand test coverage for jobs, services, and UI states.

---

### 9. How to Run (Developer Guide)

1) Prerequisites
- PHP 8.4, Composer, Node.js, npm, MySQL; Python with conda.

2) Install
- `composer install`
- `npm install`
- Copy `.env.example` → `.env`, set DB and `APP_URL`
- `php artisan key:generate`
- `php artisan migrate`
- `php artisan storage:link`

3) Python environment
- `conda create -n yolov8_m4 python=3.10 -y`
- `conda activate yolov8_m4`
- `pip install ultralytics opencv-python torch`
- Place model at `/Users/jfrtenebroso/Developer/Thesis/best.pt`

4) Run locally
- Option A (recommended): Valet/Herd
- Option B: `composer dev` (after `npm install`)
- Option C: Manual
  - `php artisan serve`
  - `php artisan queue:work --tries=1 --timeout=900`

5) Use
- Visit `/admin` → Videos → Create → Upload
- Wait for status to change to Completed; open the record to view both videos

---

### 10. Appendix: Key Files

- `app/Providers/Filament/AdminPanelProvider.php` – Filament panel config and widgets
- `app/Filament/Resources/VideoResource.php` – Resource schema and table
- `app/Filament/Resources/VideoResource/Pages/*` – CRUD pages
- `resources/views/filament/infolists/video-player.blade.php` – Video playback
- `app/Jobs/ProcessVideoJob.php` – Queue job
- `app/Services/VideoInferenceService.php` – Inference orchestration
- `config/filesystems.php` – Storage disks
- `config/livewire.php` – Upload limits and rules
- `database/migrations/*create_videos_table.php` – DB schema

---

### 11. Change Log (Highlights)

- Added storage disks for videos and processed outputs
- Implemented large-file uploads via Livewire and PHP configuration
- Created `Video` model, migration, and Filament Resource with pages
- Built `ProcessVideoJob` and `VideoInferenceService` with conda integration
- Improved resilience: logging, truncation of errors, and deterministic output paths
- Fixed multiple environment and path issues discovered during iterative testing



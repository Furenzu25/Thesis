@php
    $video = $getRecord();
    $videoId = 'video-' . uniqid();
@endphp

<div class="space-y-6">
    @if($video->processed_path)
        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        Processed Video with Detections
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        YOLOv8x inference with color-coded bounding boxes and confidence scores
                    </p>
                </div>
                
                @if($video->privacy_blur_enabled)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        Privacy Protected
                    </span>
                @endif
            </div>
            
            <div class="relative bg-black rounded-lg overflow-hidden">
                <video 
                    id="{{ $videoId }}_processed"
                    class="w-full rounded-lg"
                    controlsList="nodownload"
                    preload="metadata"
                >
                    <source src="{{ $video->processed_video_url }}" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
                
                <!-- Custom Controls -->
                <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4">
                    <div class="flex items-center gap-3 text-white">
                        <!-- Play/Pause -->
                        <button 
                            onclick="togglePlay('{{ $videoId }}_processed')"
                            class="hover:text-primary-400 transition"
                        >
                            <svg id="{{ $videoId }}_processed_play_icon" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                            </svg>
                        </button>
                        
                        <!-- Time Display -->
                        <span id="{{ $videoId }}_processed_time" class="text-sm font-mono">0:00 / 0:00</span>
                        
                        <!-- Progress Bar -->
                        <div class="flex-1">
                            <input 
                                type="range" 
                                id="{{ $videoId }}_processed_progress"
                                min="0" 
                                max="100" 
                                value="0" 
                                class="w-full h-1 bg-gray-600 rounded-lg appearance-none cursor-pointer"
                                oninput="seekVideo('{{ $videoId }}_processed', this.value)"
                            >
                        </div>
                        
                        <!-- Speed Control -->
                        <select 
                            onchange="changeSpeed('{{ $videoId }}_processed', this.value)"
                            class="text-xs bg-gray-700 border-gray-600 rounded px-2 py-1"
                        >
                            <option value="0.5">0.5x</option>
                            <option value="0.75">0.75x</option>
                            <option value="1" selected>1x</option>
                            <option value="1.25">1.25x</option>
                            <option value="1.5">1.5x</option>
                            <option value="2">2x</option>
                        </select>
                        
                        <!-- Fullscreen -->
                        <button 
                            onclick="toggleFullscreen('{{ $videoId }}_processed')"
                            class="hover:text-primary-400 transition"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Detection Info -->
            @if($video->total_detections)
                <div class="mt-3 flex flex-wrap gap-4 text-sm">
                    <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        <span>{{ number_format($video->total_detections) }} total detections</span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>{{ number_format($video->total_frames) }} frames</span>
                    </div>
                    @if($video->confidence_threshold)
                        <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            <span>{{ ($video->confidence_threshold * 100) }}% confidence threshold</span>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif
    
    <!-- Original Video -->
    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-6 border border-gray-200 dark:border-gray-700">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                Original Video
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Source footage without inference overlays
            </p>
        </div>
        
        <video 
            controls 
            class="w-full rounded-lg bg-black"
            controlsList="nodownload"
            preload="metadata"
        >
            <source src="{{ $video->original_video_url }}" type="video/mp4">
            Your browser does not support the video tag.
        </video>
        
        @if($video->duration_formatted)
            <div class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                Duration: {{ $video->duration_formatted }}
            </div>
        @endif
    </div>
</div>

<script>
function togglePlay(videoId) {
    const video = document.getElementById(videoId);
    const playIcon = document.getElementById(videoId + '_play_icon');
    
    if (video.paused) {
        video.play();
        playIcon.innerHTML = '<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>';
    } else {
        video.pause();
        playIcon.innerHTML = '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>';
    }
}

function seekVideo(videoId, value) {
    const video = document.getElementById(videoId);
    const time = (value / 100) * video.duration;
    video.currentTime = time;
}

function changeSpeed(videoId, speed) {
    const video = document.getElementById(videoId);
    video.playbackRate = parseFloat(speed);
}

function toggleFullscreen(videoId) {
    const video = document.getElementById(videoId);
    if (video.requestFullscreen) {
        video.requestFullscreen();
    } else if (video.webkitRequestFullscreen) {
        video.webkitRequestFullscreen();
    }
}

function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// Update progress and time for processed video
document.addEventListener('DOMContentLoaded', function() {
    @if($video->processed_path)
        const processedVideo = document.getElementById('{{ $videoId }}_processed');
        const processedProgress = document.getElementById('{{ $videoId }}_processed_progress');
        const processedTime = document.getElementById('{{ $videoId }}_processed_time');
        
        if (processedVideo) {
            processedVideo.addEventListener('timeupdate', function() {
                const percent = (processedVideo.currentTime / processedVideo.duration) * 100;
                processedProgress.value = percent;
                processedTime.textContent = formatTime(processedVideo.currentTime) + ' / ' + formatTime(processedVideo.duration);
            });
            
            processedVideo.addEventListener('loadedmetadata', function() {
                processedTime.textContent = '0:00 / ' + formatTime(processedVideo.duration);
            });
        }
    @endif
});
</script>

<style>
input[type="range"]::-webkit-slider-thumb {
    appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #3b82f6;
    cursor: pointer;
}

input[type="range"]::-moz-range-thumb {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #3b82f6;
    cursor: pointer;
    border: none;
}
</style>

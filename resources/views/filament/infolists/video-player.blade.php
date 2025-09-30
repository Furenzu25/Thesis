<div class="space-y-4">
    @if($getRecord()->processed_path)
        <div>
            <h3 class="text-lg font-semibold mb-2">Processed Video (With Inference)</h3>
            <video controls class="w-full max-w-4xl rounded-lg shadow-lg">
                <source src="{{ $getRecord()->processed_video_url }}" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>
    @endif
    
    <div>
        <h3 class="text-lg font-semibold mb-2">Original Video</h3>
        <video controls class="w-full max-w-4xl rounded-lg shadow-lg">
            <source src="{{ $getRecord()->original_video_url }}" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </div>
</div>

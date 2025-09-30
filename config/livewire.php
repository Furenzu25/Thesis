<?php

return [
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => 'file|max:524288', // 512MB in KB
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp3', 
            'mp4', 'mov', 'avi', 'wmv', 'mpeg', 'mpg', 'webm',
        ],
        'max_upload_time' => 600, // 10 minutes in seconds
    ],
    
    // Add these settings
    'temporary_upload_directory' => null,
    'manifest_path' => null,
    'back_button_cache' => false,
    'render_on_redirect' => false,
];

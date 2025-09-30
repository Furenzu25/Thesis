<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Filament\Resources\VideoResource;
use App\Jobs\ProcessVideoJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateVideo extends CreateRecord
{
    protected static string $resource = VideoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Get file info after upload
        if (isset($data['original_path'])) {
            $filePath = $data['original_path'];
            
            // Get file size
            $data['file_size'] = Storage::disk('videos')->size($filePath);
            
            // Get original filename
            $data['original_filename'] = basename($filePath);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Dispatch job to process video
        ProcessVideoJob::dispatch($this->record);
        
        // Show success notification
        Notification::make()
            ->success()
            ->title('Video Uploaded Successfully')
            ->body('Your video is now being processed. You will be notified when it\'s complete.')
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Video uploaded and queued for processing';
    }
}

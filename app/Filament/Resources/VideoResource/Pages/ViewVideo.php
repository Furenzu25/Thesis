<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Filament\Resources\VideoResource;
use App\Jobs\ProcessVideoJob;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewVideo extends ViewRecord
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\Action::make('reprocess')
                ->label('Reprocess Video')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn ($record) => $record->status === 'failed')
                ->action(function ($record) {
                    $record->update(['status' => 'pending']);
                    ProcessVideoJob::dispatch($record);
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Video Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('title'),
                        Infolists\Components\TextEntry::make('description'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'processing' => 'primary',
                                'completed' => 'success',
                                'failed' => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('file_size_formatted')
                            ->label('File Size'),
                        Infolists\Components\TextEntry::make('processing_duration')
                            ->label('Processing Duration (seconds)')
                            ->visible(fn ($record) => $record->processing_duration),
                        Infolists\Components\TextEntry::make('error_message')
                            ->visible(fn ($record) => $record->status === 'failed')
                            ->color('danger'),
                    ])
                    ->columns(2),
                
                Infolists\Components\Section::make('Videos')
                    ->schema([
                        Infolists\Components\ViewEntry::make('videos')
                            ->view('filament.infolists.video-player'),
                    ])
                    ->visible(fn ($record) => $record->isProcessed()),
            ]);
    }
}

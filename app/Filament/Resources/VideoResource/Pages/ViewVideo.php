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
                Infolists\Components\Section::make('Video Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight('bold'),
                        Infolists\Components\TextEntry::make('description')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'processing' => 'primary',
                                'completed' => 'success',
                                'failed' => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('processing_progress')
                            ->label('Processing Progress')
                            ->formatStateUsing(fn ($state) => $state ? "{$state}%" : 'N/A')
                            ->visible(fn ($record) => $record->isProcessing()),
                        Infolists\Components\TextEntry::make('error_message')
                            ->visible(fn ($record) => $record->hasFailed())
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Infolists\Components\Section::make('Metadata')
                    ->description('Location, time, and weather information')
                    ->schema([
                        Infolists\Components\TextEntry::make('location_name')
                            ->label('Location')
                            ->placeholder('Not specified')
                            ->icon('heroicon-o-map-pin'),
                        Infolists\Components\TextEntry::make('time_of_day')
                            ->label('Time of Day')
                            ->placeholder('Not specified')
                            ->icon('heroicon-o-clock'),
                        Infolists\Components\TextEntry::make('weather_condition')
                            ->label('Weather')
                            ->placeholder('Not specified')
                            ->icon('heroicon-o-cloud'),
                    ])
                    ->columns(3)
                    ->collapsible(),
                
                Infolists\Components\Section::make('Video Properties')
                    ->description('Technical specifications of the uploaded video')
                    ->schema([
                        Infolists\Components\TextEntry::make('duration_formatted')
                            ->label('Duration')
                            ->icon('heroicon-o-play'),
                        Infolists\Components\TextEntry::make('resolution')
                            ->label('Resolution')
                            ->icon('heroicon-o-tv'),
                        Infolists\Components\TextEntry::make('video_format')
                            ->label('Format')
                            ->formatStateUsing(fn ($state) => strtoupper($state ?? 'MP4'))
                            ->icon('heroicon-o-film'),
                        Infolists\Components\TextEntry::make('file_size_formatted')
                            ->label('File Size')
                            ->icon('heroicon-o-document'),
                        Infolists\Components\TextEntry::make('total_frames')
                            ->label('Total Frames')
                            ->placeholder('Not available')
                            ->icon('heroicon-o-squares-2x2'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Uploaded At')
                            ->dateTime()
                            ->icon('heroicon-o-calendar'),
                    ])
                    ->columns(3)
                    ->collapsible(),
                
                Infolists\Components\Section::make('Inference Configuration')
                    ->description('Detection settings used for processing')
                    ->schema([
                        Infolists\Components\TextEntry::make('confidence_threshold')
                            ->label('Confidence Threshold')
                            ->formatStateUsing(fn ($state) => $state ? ($state * 100) . '%' : '25%'),
                        Infolists\Components\TextEntry::make('privacy_blur_enabled')
                            ->label('Privacy Blur')
                            ->formatStateUsing(fn ($state) => $state ? 'Enabled' : 'Disabled')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'gray'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),
                
                Infolists\Components\Section::make('Detection Statistics')
                    ->description('Results from model inference')
                    ->schema([
                        Infolists\Components\TextEntry::make('total_detections')
                            ->label('Total Detections')
                            ->formatStateUsing(fn ($state) => number_format($state ?? 0))
                            ->icon('heroicon-o-eye'),
                        Infolists\Components\TextEntry::make('average_detections_per_frame')
                            ->label('Average Detections/Frame')
                            ->formatStateUsing(fn ($state) => number_format($state ?? 0, 2))
                            ->icon('heroicon-o-chart-bar'),
                        Infolists\Components\TextEntry::make('processing_duration')
                            ->label('Processing Time')
                            ->formatStateUsing(fn ($state) => $state ? "{$state}s" : 'N/A')
                            ->icon('heroicon-o-clock'),
                        Infolists\Components\TextEntry::make('processed_frames')
                            ->label('Processed Frames')
                            ->formatStateUsing(fn ($state) => number_format($state ?? 0))
                            ->icon('heroicon-o-check-circle'),
                    ])
                    ->columns(4)
                    ->visible(fn ($record) => $record->isProcessed())
                    ->collapsible(),
                
                Infolists\Components\Section::make('Video Player')
                    ->schema([
                        Infolists\Components\ViewEntry::make('videos')
                            ->view('filament.infolists.video-player'),
                    ])
                    ->visible(fn ($record) => $record->original_path),
            ]);
    }
}

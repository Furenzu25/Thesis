<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VideoResource\Pages;
use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationLabel = 'Videos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Video Upload')
                    ->description('Upload MP4 video. Recommended duration: under 5 minutes. Ensure a clear frontal view of traffic with minimal camera movement.')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., MatinaCrossing_MorningTraffic'),
                        
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('Optional description of the video content'),
                        
                        Forms\Components\FileUpload::make('original_path')
                            ->label('Video File')
                            ->disk('videos')
                            ->directory('uploads')
                            ->acceptedFileTypes(['video/mp4'])
                            ->maxSize(524288) // 512 MB
                            ->required()
                            ->downloadable()
                            ->visibility('private')
                            ->helperText('Upload .mp4 file (max 512 MB). Recommended: under 5 minutes.')
                            ->reactive(),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Forms\Components\Section::make('Metadata')
                    ->description('These fields help identify and categorize your video for consistent labeling')
                    ->schema([
                        Forms\Components\TextInput::make('location_name')
                            ->label('Location Name')
                            ->placeholder('e.g., JP Laurel - Bajada')
                            ->maxLength(255)
                            ->helperText('Traffic location or intersection name'),
                        
                        Forms\Components\Select::make('time_of_day')
                            ->label('Time of Day')
                            ->options([
                                'Early Morning' => 'Early Morning (5:00-7:00)',
                                'Morning' => 'Morning (7:00-10:00)',
                                'Midday' => 'Midday (10:00-14:00)',
                                'Afternoon' => 'Afternoon (14:00-17:00)',
                                'Evening' => 'Evening (17:00-20:00)',
                                'Night' => 'Night (20:00-5:00)',
                            ])
                            ->native(false)
                            ->placeholder('Select time of day'),
                        
                        Forms\Components\Select::make('weather_condition')
                            ->label('Weather Condition')
                            ->options([
                                'Clear' => 'Clear',
                                'Partly Cloudy' => 'Partly Cloudy',
                                'Cloudy' => 'Cloudy',
                                'Light Rain' => 'Light Rain',
                                'Heavy Rain' => 'Heavy Rain',
                                'Foggy' => 'Foggy',
                            ])
                            ->native(false)
                            ->placeholder('Select weather condition'),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Forms\Components\Section::make('Advanced Configuration')
                    ->description('For debugging and experimentation purposes (optional)')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('confidence_threshold')
                                    ->label('Detection Confidence Threshold')
                                    ->numeric()
                                    ->default(0.25)
                                    ->minValue(0.10)
                                    ->maxValue(0.90)
                                    ->step(0.05)
                                    ->helperText('Range: 0.10 - 0.90 (Default: 0.25)')
                                    ->suffix('%')
                                    ->reactive(),
                                
                                Forms\Components\Toggle::make('privacy_blur_enabled')
                                    ->label('Enable Privacy Blur')
                                    ->helperText('Anonymize faces and license plates (if supported)')
                                    ->default(false),
                            ]),
                        
                        Forms\Components\Select::make('traffic_direction')
                            ->label('Traffic Direction (Line Crossing Detection)')
                            ->options([
                                'none' => 'None - Standard Detection Only',
                                'left_to_right' => 'Left → Right (Line 150px from right edge)',
                                'right_to_left' => 'Right → Left (Line 150px from left edge)',
                                'top_to_bottom' => 'Top → Bottom (Line 150px from bottom edge)',
                                'bottom_to_top' => 'Bottom → Top (Line 150px from top edge)',
                            ])
                            ->default('none')
                            ->helperText('Enable line crossing detection to track and count vehicles. Green line = counting line (150px from edge). Yellow line = entry zone (120px before counting line). Vehicles must start in entry zone to be counted.')
                            ->native(false)
                            ->searchable(),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                
                Tables\Columns\TextColumn::make('metadata_label')
                    ->label('Metadata')
                    ->limit(40)
                    ->tooltip(fn (Video $record): string => $record->metadata_label),
                
                Tables\Columns\TextColumn::make('duration_formatted')
                    ->label('Duration')
                    ->sortable('duration_seconds'),
                
                Tables\Columns\TextColumn::make('resolution')
                    ->label('Resolution')
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('file_size_formatted')
                    ->label('File Size'),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'primary' => 'processing',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                
                Tables\Columns\TextColumn::make('processing_progress')
                    ->label('Progress')
                    ->formatStateUsing(fn ($state) => $state ? "{$state}%" : 'N/A')
                    ->visible(fn (?Video $record) => $record?->isProcessing() ?? false),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                
                Tables\Filters\SelectFilter::make('location_name')
                    ->label('Location')
                    ->options(fn () => Video::whereNotNull('location_name')
                        ->distinct()
                        ->pluck('location_name', 'location_name')
                        ->toArray()),
                
                Tables\Filters\SelectFilter::make('time_of_day')
                    ->options([
                        'Early Morning' => 'Early Morning',
                        'Morning' => 'Morning',
                        'Midday' => 'Midday',
                        'Afternoon' => 'Afternoon',
                        'Evening' => 'Evening',
                        'Night' => 'Night',
                    ]),
                
                Tables\Filters\SelectFilter::make('weather_condition')
                    ->options([
                        'Clear' => 'Clear',
                        'Partly Cloudy' => 'Partly Cloudy',
                        'Cloudy' => 'Cloudy',
                        'Light Rain' => 'Light Rain',
                        'Heavy Rain' => 'Heavy Rain',
                        'Foggy' => 'Foggy',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(function (Video $record) {
                        // Clean up files
                        if ($record->original_path) {
                            Storage::disk('videos')->delete($record->original_path);
                        }
                        if ($record->processed_path) {
                            Storage::disk('processed_videos')->delete($record->processed_path);
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVideos::route('/'),
            'create' => Pages\CreateVideo::route('/create'),
            'view' => Pages\ViewVideo::route('/{record}'),
            'edit' => Pages\EditVideo::route('/{record}/edit'),
        ];
    }
}



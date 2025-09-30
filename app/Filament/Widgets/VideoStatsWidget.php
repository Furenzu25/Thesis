<?php

namespace App\Filament\Widgets;

use App\Models\Video;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VideoStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Videos', Video::count())
                ->icon('heroicon-o-video-camera'),
            
            Stat::make('Processed Videos', Video::where('status', 'completed')->count())
                ->color('success')
                ->icon('heroicon-o-check-circle'),
            
            Stat::make('Processing', Video::where('status', 'processing')->count())
                ->color('primary')
                ->icon('heroicon-o-arrow-path'),
            
            Stat::make('Failed', Video::where('status', 'failed')->count())
                ->color('danger')
                ->icon('heroicon-o-x-circle'),
        ];
    }
}
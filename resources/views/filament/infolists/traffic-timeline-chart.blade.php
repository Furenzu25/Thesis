@php
    $timeline = $getState() ?? [];
    $chartId = 'traffic-chart-' . uniqid();
@endphp

<div class="w-full">
    @if(!empty($timeline))
        <div class="bg-white dark:bg-gray-900 rounded-lg p-4">
            <canvas id="{{ $chartId }}" style="max-height: 400px;"></canvas>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const ctx = document.getElementById('{{ $chartId }}');
                
                if (!ctx) return;
                
                const data = @json($timeline);
                
                const labels = data.map(item => `Min ${item.minute}`);
                const counts = data.map(item => item.count);
                
                // Find max for scaling
                const maxCount = Math.max(...counts);
                const suggestedMax = Math.ceil(maxCount * 1.1);
                
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Vehicles Detected',
                            data: counts,
                            backgroundColor: 'rgba(59, 130, 246, 0.5)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1,
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        interaction: {
                            intersect: false,
                            mode: 'index',
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: getComputedStyle(document.documentElement)
                                        .getPropertyValue('--gray-700').trim() || '#374151',
                                    font: {
                                        size: 12,
                                        weight: 'bold'
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: 'Traffic Volume by Minute',
                                color: getComputedStyle(document.documentElement)
                                    .getPropertyValue('--gray-900').trim() || '#111827',
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return ` ${context.parsed.y} vehicles`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                suggestedMax: suggestedMax,
                                ticks: {
                                    stepSize: Math.ceil(suggestedMax / 10),
                                    color: getComputedStyle(document.documentElement)
                                        .getPropertyValue('--gray-600').trim() || '#4b5563'
                                },
                                grid: {
                                    color: getComputedStyle(document.documentElement)
                                        .getPropertyValue('--gray-200').trim() || '#e5e7eb'
                                },
                                title: {
                                    display: true,
                                    text: 'Number of Vehicles',
                                    color: getComputedStyle(document.documentElement)
                                        .getPropertyValue('--gray-700').trim() || '#374151',
                                    font: {
                                        size: 12,
                                        weight: 'bold'
                                    }
                                }
                            },
                            x: {
                                ticks: {
                                    color: getComputedStyle(document.documentElement)
                                        .getPropertyValue('--gray-600').trim() || '#4b5563',
                                    maxRotation: 45,
                                    minRotation: 0
                                },
                                grid: {
                                    display: false
                                },
                                title: {
                                    display: true,
                                    text: 'Time (Minutes)',
                                    color: getComputedStyle(document.documentElement)
                                        .getPropertyValue('--gray-700').trim() || '#374151',
                                    font: {
                                        size: 12,
                                        weight: 'bold'
                                    }
                                }
                            }
                        }
                    }
                });
            });
        </script>
    @else
        <div class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No timeline data available
        </div>
    @endif
</div>


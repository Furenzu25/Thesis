<div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
    @if(!empty($getState()))
        <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Vehicle Class
                    </th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Count
                    </th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Percentage
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                @php
                    $classCounts = $getState();
                    $total = array_sum($classCounts);
                    
                    // Sort by count descending
                    arsort($classCounts);
                    
                    // Define color palette for vehicle classes
                    $colors = [
                        'motorcycle' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                        'sedan' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                        'medium_vehicle' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                        'medium-vehicle' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                        'jeepney' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
                        'jeepney-uso' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
                        'jeepney-multicab' => 'bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-300',
                        'bus' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                        'truck' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
                    ];
                @endphp
                
                @foreach($classCounts as $className => $count)
                    @php
                        $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                        $colorClass = $colors[strtolower($className)] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
                        $formattedClassName = ucwords(str_replace(['_', '-'], ' ', $className));
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colorClass }}">
                                    {{ $formattedClassName }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ number_format($count) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-16 bg-gray-200 rounded-full h-2 dark:bg-gray-700">
                                    <div class="bg-primary-600 h-2 rounded-full" style="width: {{ $percentage }}%"></div>
                                </div>
                                <span class="w-12 text-right">{{ $percentage }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
                
                <tr class="bg-gray-50 dark:bg-gray-800 font-semibold">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        Total
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-gray-100">
                        {{ number_format($total) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                        100%
                    </td>
                </tr>
            </tbody>
        </table>
    @else
        <div class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No class data available
        </div>
    @endif
</div>


<div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
    @php
        $crossings = $getState() ?? [];
    @endphp
    
    @if(!empty($crossings))
        <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Vehicle Class
                    </th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Line Crossings
                    </th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Percentage
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                @php
                    $total = array_sum($crossings);
                    
                    // Sort by count descending
                    arsort($crossings);
                    
                    // Define color palette
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
                
                @foreach($crossings as $className => $count)
                    @php
                        $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                        $colorClass = $colors[strtolower($className)] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
                        $formattedClassName = ucwords(str_replace(['_', '-'], ' ', $className));
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colorClass }}">
                                    {{ $formattedClassName }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-lg font-bold text-green-600 dark:text-green-400">
                                {{ number_format($count) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-16 bg-gray-200 rounded-full h-2 dark:bg-gray-700">
                                    <div class="bg-green-600 h-2 rounded-full" style="width: {{ $percentage }}%"></div>
                                </div>
                                <span class="w-12 text-right">{{ $percentage }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
                
                <tr class="bg-green-50 dark:bg-green-900/20 font-bold border-t-2 border-green-200 dark:border-green-700">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                            </svg>
                            <span class="text-sm text-gray-900 dark:text-gray-100">Total Crossings</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                        <span class="text-xl font-bold text-green-600 dark:text-green-400">
                            {{ number_format($total) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                        100%
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div class="px-6 py-3 bg-blue-50 dark:bg-blue-900/20 border-t border-blue-200 dark:border-blue-700">
            <div class="flex items-start gap-2 text-sm text-blue-800 dark:text-blue-300">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <p class="font-semibold">About Line Crossing Detection</p>
                    <p class="mt-1 text-xs">Each vehicle is assigned a unique ID and tracked across frames. Count increments only once when the tracked ID crosses the virtual line in the specified direction.</p>
                </div>
            </div>
        </div>
    @else
        <div class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No line crossing data available
        </div>
    @endif
</div>


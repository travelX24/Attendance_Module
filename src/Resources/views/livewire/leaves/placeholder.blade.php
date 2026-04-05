@php
    $locale = app()->getLocale();
    $isRtl  = in_array(substr($locale, 0, 2), ['ar','fa','ur','he']);
    $dir    = $isRtl ? 'rtl' : 'ltr';
@endphp

<div class="p-6 space-y-6 animate-pulse" dir="{{ $dir }}">
    
    {{-- Header Skeleton --}}
    <div class="flex justify-between items-start">
        <div class="space-y-3">
            <div class="h-8 bg-gray-200 rounded w-64"></div>
            <div class="h-4 bg-gray-100 rounded w-96"></div>
        </div>
        <div class="h-10 bg-indigo-100 rounded-xl w-32"></div>
    </div>

    {{-- Tabs Skeleton --}}
    <div class="flex items-center gap-2 bg-gray-100/50 p-1 rounded-xl w-fit">
        @for($i=0; $i<3; $i++)
            <div class="h-8 bg-gray-200 rounded-lg w-28"></div>
        @endfor
    </div>

    {{-- Filters Skeleton --}}
    <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-5 gap-4">
            @for($i=0; $i<10; $i++)
                <div class="space-y-2">
                    <div class="h-3 bg-gray-200 rounded w-2/3"></div>
                    <div class="h-9 bg-gray-50 rounded w-full border border-gray-100"></div>
                </div>
            @endfor
        </div>
    </div>

    {{-- Content Table Skeleton --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="p-4 border-b border-gray-100">
            <div class="h-5 bg-gray-200 rounded w-48"></div>
        </div>
        <div class="p-4 space-y-4">
            @for($i=0; $i<6; $i++)
                <div class="grid grid-cols-6 gap-6 py-4 border-b border-gray-50">
                    <div class="h-4 bg-gray-200 rounded col-span-2"></div>
                    <div class="h-4 bg-gray-100 rounded"></div>
                    <div class="h-4 bg-gray-100 rounded"></div>
                    <div class="h-4 bg-gray-100 rounded"></div>
                    <div class="h-8 bg-indigo-50 rounded-lg"></div>
                </div>
            @endfor
        </div>
    </div>
</div>

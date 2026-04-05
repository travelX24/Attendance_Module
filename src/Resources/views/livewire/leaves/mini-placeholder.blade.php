<div class="space-y-4 animate-pulse">
    {{-- Content Table Skeleton (Mainly for tabs/inner loading) --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="p-4 border-b border-gray-100 flex justify-between items-center">
            <div class="h-5 bg-gray-200 rounded w-48"></div>
            <div class="h-8 bg-gray-100 rounded-lg w-24"></div>
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

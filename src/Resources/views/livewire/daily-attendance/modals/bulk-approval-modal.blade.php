{{-- Bulk Approval Summary Modal --}}
    <x-ui.modal wire:model="showBulkApprovalModal" max-width="md">
        <x-slot name="title">{{ tr('Bulk Approval Summary') }}</x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div class="p-4 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="text-center mb-4">
                         <p class="text-sm text-gray-500 mb-1">{{ tr('You are about to approve') }}</p>
                         <div class="text-3xl font-bold text-gray-900">{{ $bulkSummary['total_count'] ?? 0 }}</div>
                         <p class="text-sm text-gray-500">{{ tr('Attendance Records') }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 border-t border-gray-200 pt-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600">{{ $bulkSummary['ready_count'] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">{{ tr('Ready to Approve') }}</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold {{ ($bulkSummary['issues_count'] ?? 0) > 0 ? 'text-yellow-600' : 'text-gray-400' }}">
                                {{ $bulkSummary['issues_count'] ?? 0 }}
                            </div>
                            <div class="text-xs text-gray-500">{{ tr('With Notices/Issues') }}</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t border-gray-200 text-center">
                         <div class="text-xs text-gray-500 mb-1">{{ tr('Average Working Hours') }}</div>
                         <div class="font-mono font-bold">{{ number_format($bulkSummary['avg_hours'] ?? 0, 2) }} {{ tr('Hrs') }}</div>
                    </div>
                </div>

                @if(($bulkSummary['issues_count'] ?? 0) > 0)
                    <div class="p-3 bg-yellow-50 border border-yellow-100 rounded-lg flex items-start gap-3">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                        <div class="text-sm text-yellow-800">
                            <span class="font-bold">{{ tr('Warning:') }}</span>
                            {{ tr('Some records have issues (Late, Early, or Manual Edits). Approving will mark them as valid for payroll.') }}
                        </div>
                    </div>
                @else
                    <div class="p-3 bg-green-50 border border-green-100 rounded-lg flex items-start gap-3">
                        <i class="fas fa-check-circle text-green-600 mt-0.5"></i>
                        <div class="text-sm text-green-800">
                            {{ tr('All selected records look good and are ready for approval.') }}
                        </div>
                    </div>
                @endif
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-2 w-full">
                <x-ui.secondary-button wire:click="$set('showBulkApprovalModal', false)">
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                @can('attendance.manage')
                <x-ui.primary-button wire:click="confirmBulkApprove" class="gap-2">
                    <i class="fas fa-check-double"></i>
                    <span>{{ tr('Confirm Approval') }}</span>
                </x-ui.primary-button>
                @endcan
            </div>
        </x-slot>
    </x-ui.modal>

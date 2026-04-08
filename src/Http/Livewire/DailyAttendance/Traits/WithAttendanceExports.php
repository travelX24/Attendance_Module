<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Attendance\Models\AttendanceDailyLog;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\ExcelExportService;

trait WithAttendanceExports
{
   private function applyExportFilters($query)
    {
        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $query->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        if ($this->date_from) $query->where('attendance_date', '>=', $this->date_from);
        if ($this->date_to) $query->where('attendance_date', '<=', $this->date_to);
        if ($this->attendance_status_filter !== 'all') $query->where('attendance_status', $this->attendance_status_filter);
        if ($this->approval_status_filter !== 'all') $query->where('approval_status', $this->approval_status_filter);
        if ($this->work_schedule_id !== 'all') $query->where('work_schedule_id', $this->work_schedule_id);

        if ($this->compliance_from !== '') $query->where('compliance_percentage', '>=', $this->compliance_from);
        if ($this->compliance_to !== '') $query->where('compliance_percentage', '<=', $this->compliance_to);

        if ($this->search) {
            $query->whereHas('employee', function ($q) {
                $q->where('name_ar', 'like', '%' . $this->search . '%')
                ->orWhere('name_en', 'like', '%' . $this->search . '%')
                ->orWhere('employee_no', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->department_id !== 'all') {
            $query->whereHas('employee', function ($q) { $q->where('department_id', $this->department_id); });
        }

        if ($this->branch_id !== 'all') {
            $query->whereHas('employee', function ($q) { $q->where('branch_id', $this->branch_id); });
        }

        if ($this->job_title_id !== 'all') {
            $query->whereHas('employee', function ($q) { $q->where('job_title_id', $this->job_title_id); });
        }

        return $query;
    }

    public function exportExcel()
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyLog::forCompany($companyId)->with(['employee', 'workSchedule']);
        $query = $this->applyExportFilters($query);

        $logs = $query->orderByDesc('attendance_date')->get();

        $filename = "attendance_report_" . now()->format('Y-m-d');
        $headers = [
            tr('Employee No'), tr('Employee Name'), tr('Date'), 
            tr('Status'), tr('In'), tr('Out'), tr('Actual Hours'), tr('Compliance')
        ];

        $data = $logs->map(function ($row) {
            return [
                $row->employee->employee_no ?? '-',
                $row->employee->name_ar ?? $row->employee->name_en ?? '-',
                $row->attendance_date->toDateString(),
                tr(ucfirst($row->attendance_status)),
                $row->check_in_hm ?? '-',
                $row->check_out_hm ?? '-',
                $row->actual_hours,
                $row->compliance_percentage . '%'
            ];
        })->toArray();

        return app(ExcelExportService::class)->export($filename, $headers, $data);
    }

    public function exportPDF()
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyLog::forCompany($companyId)->with(['employee', 'workSchedule']);
        $query = $this->applyExportFilters($query);
        $logs = $query->orderByDesc('attendance_date')->get();

        // ✅ Reshape Arabic text for PDF
        $logs->each(function($log) {
            $log->pdf_name = $this->pdfReshape($log->employee->name_ar ?? $log->employee->name_en ?? '-');
            $log->pdf_schedule = $this->pdfReshape($log->workSchedule?->name ?? '-');
            $log->pdf_status = $this->pdfReshape(tr(ucfirst($log->attendance_status)));
            $log->pdf_approval = $this->pdfReshape(tr(ucfirst($log->approval_status)));
        });

        $pdf = Pdf::loadView('attendance::pdf.daily-attendance', [
            'logs' => $logs,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'company' => \Athka\Saas\Models\SaasCompany::find($companyId),
            'reshaper' => function($t) { return $this->pdfReshape($t); }
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function() use ($pdf) {
            echo $pdf->stream();
        }, "attendance_report.pdf");
    }

    private function pdfReshape($text)
    {
        if (class_exists('\Athka\Employees\Support\ArabicHelper')) {
            return \Athka\Employees\Support\ArabicHelper::prepareForPdf((string)$text);
        }
        return $text;
    }
}



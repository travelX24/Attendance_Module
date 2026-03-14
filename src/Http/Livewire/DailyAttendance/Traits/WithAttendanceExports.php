<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Attendance\Models\AttendanceDailyLog;
use Barryvdh\DomPDF\Facade\Pdf;

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

        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=attendance_report_" . now()->format('Y-m-d') . ".csv",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $callback = function() use ($logs) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            fputcsv($file, [
                tr('Employee No'), tr('Employee Name'), tr('Date'), 
                tr('Status'), tr('In'), tr('Out'), tr('Actual Hours'), tr('Compliance')
            ]);

            foreach ($logs as $row) {
                fputcsv($file, [
                    $row->employee->employee_no ?? '-',
                    $row->employee->name_ar ?? $row->employee->name_en ?? '-',
                    $row->attendance_date->toDateString(),
                    tr($row->attendance_status),
                    $row->check_in_hm ?? '-',
                    $row->check_out_hm ?? '-',
                    $row->actual_hours,
                    $row->compliance_percentage . '%'
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportPDF()
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyLog::forCompany($companyId)->with(['employee', 'workSchedule']);
        $query = $this->applyExportFilters($query);
        $logs = $query->orderByDesc('attendance_date')->get();

        $pdf = Pdf::loadView('attendance::pdf.daily-attendance', [
            'logs' => $logs,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'company' => \Athka\Saas\Models\SaasCompany::find($companyId)
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function() use ($pdf) {
            echo $pdf->stream();
        }, "attendance_report.pdf");
    }
}



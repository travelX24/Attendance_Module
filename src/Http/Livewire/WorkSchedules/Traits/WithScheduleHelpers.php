<?php

namespace Athka\Attendance\Http\Livewire\WorkSchedules\Traits;

use Athka\Attendance\Models\AttendanceAuditLog;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Athka\Employees\Models\Employee;
trait WithScheduleHelpers
{
    protected function getCompanyId(): int
    {
        if (app()->bound('currentCompany')) {
            return (int) app('currentCompany')->id;
        }

        return (int) (auth()->user()->saas_company_id ?? 0);
    }

    public function auditLog(
        string $action,
        ?int $employeeId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        $before = null,
        $after = null,
        array $meta = []
    ): void {
        $companyId = $this->getCompanyId();
        if (!$companyId) return;

        AttendanceAuditLog::create([
            'saas_company_id' => $companyId,
            'actor_user_id'   => auth()->id(),
            'employee_id'     => $employeeId,
            'action'          => $action,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'before_json' => is_array($before) ? $before : (is_object($before) && method_exists($before, 'toArray') ? $before->toArray() : $before),
            'after_json' => is_array($after) ? $after : (is_object($after) && method_exists($after, 'toArray') ? $after->toArray() : $after),
            'meta_json'       => $meta,
            'ip'              => request()?->ip(),
            'user_agent'      => mb_substr((string) request()?->userAgent(), 0, 512),
        ]);
    }

    public function addBusinessDays($date, int $days)
    {
        $d = Carbon::parse($date);
        $added = 0;
        $step = $days > 0 ? 1 : -1;
        $absDays = abs($days);
        while ($added < $absDays) {
            $d->addDays($step);
            if ($d->dayOfWeek !== Carbon::FRIDAY && $d->dayOfWeek !== Carbon::SATURDAY) {
                $added++;
            }
        }
        return $d;
    }

    public function detectContractConflictEmployeeIds(int $companyId): array
    {
        // Placeholder as per existing logic
        return [];
    }

    protected function resolveJobTitlesLabelColumns(): array
    {
        $cols = Schema::getColumnListing('job_titles');
        $ar = in_array('name_ar', $cols, true) ? 'name_ar' : (in_array('title_ar', $cols, true) ? 'title_ar' : null);
        $en = in_array('name_en', $cols, true) ? 'name_en' : (in_array('title_en', $cols, true) ? 'title_en' : null);
        $fallback = in_array('name', $cols, true) ? 'name' : (in_array('title', $cols, true) ? 'title' : null);
        return [$ar, $en, $fallback];
    }

    protected function resolveEmployeeJobTitleColumn(): ?string
    {
        $cols = Schema::getColumnListing('employees');
        foreach (['job_title_id', 'designation_id', 'position_id'] as $c) {
            if (in_array($c, $cols, true)) return $c;
        }
        return null;
    }

   protected function resolveEmployeeLocationColumn(): ?string
    {
        $cols = Schema::getColumnListing('employees');

        foreach (['branch_id', 'location_id', 'work_location_id'] as $c) {
            if (in_array($c, $cols, true)) return $c;
        }

        return null;
    }

    protected function resolveEmployeeContractTypeColumn(): ?string
    {
        $cols = Schema::getColumnListing('employees');
        foreach (['contract_type', 'employment_type', 'job_type'] as $c) {
            if (in_array($c, $cols, true)) return $c;
        }
        return null;
    }


private function companyCalendarType(int $companyId): string
{
    return Cache::remember("company_calendar_type_{$companyId}", 3600, function () use ($companyId) {
        $row = DB::table('operational_calendars')
            ->where('company_id', $companyId)
            ->first(['calendar_type']);

        $type = strtolower((string) ($row->calendar_type ?? 'gregorian'));
        return in_array($type, ['hijri', 'gregorian']) ? $type : 'gregorian';
    });
}

private function normalizeCompanyDateToGregorian(?string $value, int $companyId): string
{
    $v = trim((string) ($value ?? ''));
    if ($v === '') {
        throw ValidationException::withMessages([
            'bulkFormData.start_date' => tr('Invalid date'),
        ]);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $v;
    }

    if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $v)) {
        return str_replace('/', '-', $v);
    }

    $type = $this->companyCalendarType($companyId);

    if ($type === 'hijri') {
        if (!preg_match('/^(14\d{2})[\/-](\d{2})[\/-](\d{2})$/', $v, $m)) {
            throw ValidationException::withMessages([
                'bulkFormData.start_date' => tr('Invalid Hijri date format'),
            ]);
        }

        if (!class_exists(\IntlCalendar::class) || !class_exists(\IntlTimeZone::class)) {
            throw ValidationException::withMessages([
                'bulkFormData.start_date' => tr('Intl extension is required for Hijri calendar'),
            ]);
        }

        $hy = (int) $m[1];
        $hm = (int) $m[2];
        $hd = (int) $m[3];

        $tz  = \IntlTimeZone::createTimeZone('UTC');
        $cal = \IntlCalendar::createInstance($tz, 'en_US@calendar=islamic-umalqura');

        $cal->set($hy, $hm - 1, $hd, 0, 0, 0);

        $ts = (int) floor($cal->getTime() / 1000);

        return Carbon::createFromTimestampUTC($ts)->format('Y-m-d');
    }

    try {
        return Carbon::parse($v)->format('Y-m-d');
    } catch (\Throwable $e) {
        throw ValidationException::withMessages([
            'bulkFormData.start_date' => tr('Invalid date'),
        ]);
    }
}

private function formatCompanyDate(?string $gregorianYmd, int $companyId): string
{
    if (!$gregorianYmd) return '-';

    $type = $this->companyCalendarType($companyId);
    $d = Carbon::parse($gregorianYmd)->startOfDay();

    if ($type !== 'hijri') {
        return $d->format('Y/m/d');
    }

    if (!class_exists(\IntlDateFormatter::class)) {
        return $d->format('Y/m/d');
    }

    $fmt = new \IntlDateFormatter(
        'en_US@calendar=islamic-umalqura',
        \IntlDateFormatter::NONE,
        \IntlDateFormatter::NONE,
        'UTC',
        \IntlDateFormatter::TRADITIONAL,
        'yyyy/MM/dd'
    );

    return (string) $fmt->format($d->getTimestamp());
}
protected function resolveCurrentUserLocationId(int $companyId): ?int
{
    $user = auth()->user();
    if (!$user) return null;

    // 1) Ø£Ø¹Ù…Ø¯Ø© Ù…Ø­ØªÙ…Ù„Ø© Ø¹Ù„Ù‰ users
    foreach (['branch_id', 'saas_branch_id', 'location_id', 'work_location_id', 'current_branch_id'] as $col) {
        if (isset($user->{$col}) && (int) $user->{$col} > 0) {
            return (int) $user->{$col};
        }
    }

    // 2) Ù„Ùˆ user Ù…Ø±Ø¨ÙˆØ· Ø¨Ù€ employee
    $employeeId = null;
    foreach (['employee_id', 'employeeId'] as $k) {
        if (isset($user->{$k}) && (int) $user->{$k} > 0) { $employeeId = (int) $user->{$k}; break; }
    }

    if ($employeeId) {
        $empCol = $this->resolveEmployeeLocationColumn();
        if ($empCol) {
            $v = Employee::forCompany($companyId)->whereKey($employeeId)->value($empCol);
            if ((int) $v > 0) return (int) $v;
        }
    }

    // 3) Ù„Ùˆ Ø¹Ù†Ø¯Ùƒ Branch Switcher ÙŠØ®Ø²Ù† Ø¨Ø§Ù„Ù€ session
    foreach (['branch_id', 'saas_branch_id', 'location_id', 'work_location_id', 'current_branch_id'] as $key) {
        $v = session($key);
        if ((int) $v > 0) return (int) $v;
    }

    return null;
}
protected function resolveCurrentUserAllowedLocationIds(int $companyId): array
{
    $user = auth()->user();
    if (!$user) return [0];

    $scope = $user->access_scope ?? 'all_branches';

    // âœ… all_branches => Ù†Ø®Ù„ÙŠÙ‡Ø§ Ø¨Ø¯ÙˆÙ† ÙÙ„ØªØ± (Ù†ÙØ³ Ø³Ù„ÙˆÙƒÙƒ Ø§Ù„Ø³Ø§Ø¨Ù‚: [] ÙŠØ¹Ù†ÙŠ Ù„Ø§ whereIn)
    if ($scope === 'all_branches') {
        return [];
    }

    $ids = [];

    // âœ… Ø§Ù„Ø£ÙØ¶Ù„: Ø§Ø³ØªØ®Ø¯Ù… Ø§Ù„Ù…ØµØ¯Ø± Ø§Ù„Ø±Ø³Ù…ÙŠ Ø§Ù„Ù„ÙŠ Ø¹Ù†Ø¯Ùƒ ÙÙŠ User model
    if (method_exists($user, 'accessibleBranchIds')) {
        $ids = (array) $user->accessibleBranchIds();
    } elseif (method_exists($user, 'allowedBranches')) {
        // fallback
        $ids = $user->allowedBranches()->pluck('branches.id')->all();
    }

    // âœ… fallback Ø£Ù‚ÙˆÙ‰: Ø§Ù‚Ø±Ø£ Ù…Ø¨Ø§Ø´Ø±Ø© Ù…Ù† pivot Ø§Ù„Ù„ÙŠ ØªØ³ØªØ®Ø¯Ù…Ù‡ Ø£Ù†Øª: branch_user_access
    if (empty($ids) && Schema::hasTable('branch_user_access')) {
        $cols = Schema::getColumnListing('branch_user_access');

        if (in_array('user_id', $cols, true) && in_array('branch_id', $cols, true)) {
            $q = DB::table('branch_user_access')->where('user_id', (int) $user->id);

            // Ù„Ùˆ Ù…ÙˆØ¬ÙˆØ¯ Ø¹Ù…ÙˆØ¯ Ù„Ù„Ø´Ø±ÙƒØ© ÙÙŠ pivot (Ø§Ø®ØªÙŠØ§Ø±ÙŠ)
            if (in_array('saas_company_id', $cols, true)) {
                $q->where('saas_company_id', $companyId);
            } elseif (in_array('company_id', $cols, true)) {
                $q->where('company_id', $companyId);
            }

            $ids = $q->pluck('branch_id')->all();
        }
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

    // âœ… Ù…Ù‡Ù… Ø¬Ø¯Ù‹Ø§: Ù„Ùˆ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù… "Ù…Ø­Ø¯ÙˆØ¯" ÙˆÙ…Ø§ Ø¹Ù†Ø¯Ù‡ Ø£ÙŠ ÙØ±Ø¹ Ù…Ø¶Ø¨ÙˆØ·
    // Ù„Ø§ ØªØ®Ù„ÙŠÙ‡ ÙŠØ´ÙˆÙ Ø§Ù„ÙƒÙ„ Ø¨Ø§Ù„Ø®Ø·Ø£.. Ø®Ù„ÙŠÙ‡ ÙŠØ´ÙˆÙ ÙˆÙ„Ø§ Ø´ÙŠØ¡
    return !empty($ids) ? $ids : [0];
}
}



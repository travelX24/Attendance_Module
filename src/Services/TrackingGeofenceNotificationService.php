<?php

namespace Athka\Attendance\Services;

use App\Models\User;
use App\Services\SystemNotificationService;
use Athka\Attendance\Models\TrackingGeofenceEvent;
use Athka\Attendance\Models\TrackingSession;
use Athka\Employees\Models\Employee;
use Illuminate\Support\Collection;
use Throwable;

final class TrackingGeofenceNotificationService
{
    public function __construct(
        private readonly TrackingNotificationRecipientPolicy $recipientPolicy,
    ) {
    }

    /**
     * Project-native recipient rule:
     * - the direct manager, when the manager has an active User account;
     * - active company-admin users in the same tenant.
     *
     * saas-admin is intentionally excluded from employee-level tracking alerts.
     */
    public function recipientsForEvent(
        TrackingGeofenceEvent $event,
    ): Collection {
        $companyId = (int) $event->saas_company_id;

        $employee = Employee::withoutGlobalScopes()
            ->where('saas_company_id', $companyId)
            ->find((int) $event->employee_id);

        $employeeBranchId = $employee?->branch_id !== null
            ? (int) $employee->branch_id
            : null;

        $recipients = User::query()
            ->role('company-admin')
            ->where('saas_company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->filter(
                fn (User $user) =>
                    $this->recipientPolicy->allowsCompanyAdmin(
                        $user,
                        $employeeBranchId,
                    )
            )
            ->values();

        $managerEmployeeId = (int) ($employee?->manager_id ?? 0);

        if ($managerEmployeeId > 0) {
            $managerUser = User::query()
                ->where('saas_company_id', $companyId)
                ->where('employee_id', $managerEmployeeId)
                ->where('is_active', true)
                ->first();

            if ($managerUser) {
                $recipients->push($managerUser);
            }
        }

        return $recipients
            ->unique(fn (User $user) => (int) $user->id)
            ->values();
    }

    public function notifyExit(
        TrackingGeofenceEvent $event,
    ): array {
        $locked = TrackingGeofenceEvent::query()
            ->whereKey($event->id)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            return $this->result(
                sent: false,
                code: 'event_not_found',
            );
        }

        if ($locked->exit_notification_sent_at !== null) {
            return $this->result(
                sent: false,
                code: 'already_sent',
                event: $locked,
            );
        }

        $recipients = $this->recipientsForEvent($locked);

        if ($recipients->isEmpty()) {
            return $this->result(
                sent: false,
                code: 'no_recipients',
                event: $locked,
            );
        }

        $employee = Employee::withoutGlobalScopes()
            ->find((int) $locked->employee_id);

        $nameAr = trim((string) (
            $employee?->name_ar
            ?: $employee?->name_en
            ?: ('#' . $locked->employee_id)
        ));

        $nameEn = trim((string) (
            $employee?->name_en
            ?: $employee?->name_ar
            ?: ('#' . $locked->employee_id)
        ));

        $extra = $this->eventData(
            event: $locked,
            kind: 'exit',
            employeeNameAr: $nameAr,
            employeeNameEn: $nameEn,
        );

        try {
            SystemNotificationService::send(
                $recipients,
                'Employee left the allowed work area',
                $nameEn . ' left the allowed work area during working time.',
                null,
                'warning',
                $extra,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->result(
                sent: false,
                code: 'send_failed',
                event: $locked,
                recipients: $recipients,
                message: $e->getMessage(),
            );
        }

        $locked->exit_notification_sent_at = now();

        $meta = is_array($locked->meta)
            ? $locked->meta
            : [];

        $meta['notifications']['exit'] = [
            'sent_at' => $locked->exit_notification_sent_at
                ->toIso8601String(),
            'recipient_user_ids' => $recipients
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ];

        $locked->meta = $meta;
        $locked->save();

        $event->refresh();

        return $this->result(
            sent: true,
            code: 'sent',
            event: $event,
            recipients: $recipients,
        );
    }

    public function notifyReturn(
        TrackingGeofenceEvent $event,
    ): array {
        $locked = TrackingGeofenceEvent::query()
            ->whereKey($event->id)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            return $this->result(
                sent: false,
                code: 'event_not_found',
            );
        }

        if ($locked->return_notification_sent_at !== null) {
            return $this->result(
                sent: false,
                code: 'already_sent',
                event: $locked,
            );
        }

        if ($locked->status !== TrackingGeofenceEvent::STATUS_RETURNED) {
            return $this->result(
                sent: false,
                code: 'event_not_returned',
                event: $locked,
            );
        }

        /*
         * Never send a "returned" alert without a corresponding exit alert.
         * This also lets an offline replay deliver both alerts in order.
         */
        if ($locked->exit_notification_sent_at === null) {
            $exitResult = $this->notifyExit($locked);
            $locked->refresh();

            if ($locked->exit_notification_sent_at === null) {
                return $this->result(
                    sent: false,
                    code: 'exit_notification_not_sent',
                    event: $locked,
                    message: $exitResult['code'] ?? null,
                );
            }
        }

        $recipients = $this->recipientsForEvent($locked);

        if ($recipients->isEmpty()) {
            return $this->result(
                sent: false,
                code: 'no_recipients',
                event: $locked,
            );
        }

        $employee = Employee::withoutGlobalScopes()
            ->find((int) $locked->employee_id);

        $nameAr = trim((string) (
            $employee?->name_ar
            ?: $employee?->name_en
            ?: ('#' . $locked->employee_id)
        ));

        $nameEn = trim((string) (
            $employee?->name_en
            ?: $employee?->name_ar
            ?: ('#' . $locked->employee_id)
        ));

        $minutes = round(
            ((int) $locked->counted_outside_seconds) / 60,
            1,
        );

        $extra = $this->eventData(
            event: $locked,
            kind: 'return',
            employeeNameAr: $nameAr,
            employeeNameEn: $nameEn,
        );

        try {
            SystemNotificationService::send(
                $recipients,
                'Employee returned to the allowed work area',
                $nameEn
                    . ' returned to the allowed work area after '
                    . $minutes
                    . ' minute(s) outside.',
                null,
                'success',
                $extra,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->result(
                sent: false,
                code: 'send_failed',
                event: $locked,
                recipients: $recipients,
                message: $e->getMessage(),
            );
        }

        $locked->return_notification_sent_at = now();

        $meta = is_array($locked->meta)
            ? $locked->meta
            : [];

        $meta['notifications']['return'] = [
            'sent_at' => $locked->return_notification_sent_at
                ->toIso8601String(),
            'recipient_user_ids' => $recipients
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ];

        $locked->meta = $meta;
        $locked->save();

        $event->refresh();

        return $this->result(
            sent: true,
            code: 'sent',
            event: $event,
            recipients: $recipients,
        );
    }

    /**
     * Historical replay suppresses notifications while rebuilding individual
     * points, then calls this method once on the final deterministic events.
     */
    public function notifyReplayEvents(
        TrackingSession $session,
    ): array {
        $events = TrackingGeofenceEvent::query()
            ->where('tracking_session_id', $session->id)
            ->orderBy('exited_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $exitSent = 0;
        $returnSent = 0;

        foreach ($events as $event) {
            $exit = $this->notifyExit($event);

            if (($exit['sent'] ?? false) === true) {
                $exitSent++;
            }

            if (
                $event->refresh()->status
                === TrackingGeofenceEvent::STATUS_RETURNED
            ) {
                $return = $this->notifyReturn($event);

                if (($return['sent'] ?? false) === true) {
                    $returnSent++;
                }
            }
        }

        return [
            'events' => $events->count(),
            'exit_sent' => $exitSent,
            'return_sent' => $returnSent,
        ];
    }

    private function eventData(
        TrackingGeofenceEvent $event,
        string $kind,
        string $employeeNameAr,
        string $employeeNameEn,
    ): array {
        $session = $event->session;

        $titleAr = $kind === 'exit'
            ? 'تنبيه خروج من نطاق العمل'
            : 'عودة الموظف إلى نطاق العمل';

        $titleEn = $kind === 'exit'
            ? 'Employee left the allowed work area'
            : 'Employee returned to the allowed work area';

        $bodyAr = $kind === 'exit'
            ? $employeeNameAr . ' غادر نطاق العمل المسموح أثناء وقت الدوام.'
            : $employeeNameAr
                . ' عاد إلى نطاق العمل المسموح بعد '
                . round(((int) $event->counted_outside_seconds) / 60, 1)
                . ' دقيقة خارج النطاق.';

        $bodyEn = $kind === 'exit'
            ? $employeeNameEn . ' left the allowed work area during working time.'
            : $employeeNameEn
                . ' returned to the allowed work area after '
                . round(((int) $event->counted_outside_seconds) / 60, 1)
                . ' minute(s) outside.';

        return [
            'target' => 'employee_tracking_event',
            'notification_kind' => 'tracking_geofence_' . $kind,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'body_ar' => $bodyAr,
            'body_en' => $bodyEn,
            'tracking_event_id' => (int) $event->id,
            'tracking_session_id' => (int) $event->tracking_session_id,
            'tracking_session_public_id' => $session?->public_id,
            'saas_company_id' => (int) $event->saas_company_id,
            'employee_id' => (int) $event->employee_id,
            'employee_name_ar' => $employeeNameAr,
            'employee_name_en' => $employeeNameEn,
            'branch_id' => $session?->branch_id !== null
                ? (int) $session->branch_id
                : null,
            'status' => $event->status,
            'classification' => $event->classification,
            'exited_at' => $event->exited_at?->toIso8601String(),
            'returned_at' => $event->returned_at?->toIso8601String(),
            'outside_seconds' => (int) $event->outside_seconds,
            'counted_outside_seconds' => (int) $event->counted_outside_seconds,
            'maximum_distance_to_boundary_meters' =>
                $event->maximum_distance_to_boundary_meters !== null
                    ? (float) $event->maximum_distance_to_boundary_meters
                    : null,
            'outside_route_distance_meters' =>
                (float) $event->outside_route_distance_meters,
            'exit_lat' => $event->exit_lat !== null
                ? (float) $event->exit_lat
                : null,
            'exit_lng' => $event->exit_lng !== null
                ? (float) $event->exit_lng
                : null,
            'return_lat' => $event->return_lat !== null
                ? (float) $event->return_lat
                : null,
            'return_lng' => $event->return_lng !== null
                ? (float) $event->return_lng
                : null,
        ];
    }

    private function result(
        bool $sent,
        string $code,
        ?TrackingGeofenceEvent $event = null,
        ?Collection $recipients = null,
        ?string $message = null,
    ): array {
        return [
            'sent' => $sent,
            'code' => $code,
            'event_id' => $event?->id,
            'recipient_user_ids' => $recipients
                ? $recipients
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all()
                : [],
            'message' => $message,
        ];
    }
}

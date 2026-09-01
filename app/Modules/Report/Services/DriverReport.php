<?php

namespace App\Modules\Report\Services;

use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Report\Data\DateRange;

/**
 * How the drivers are doing.
 *
 * **Super-admin only, and that is a rule this class cannot enforce by itself.**
 * Tasks are not tenant-scoped — a driver works across laundries — so unlike every
 * other report here, the scope will not quietly do the right thing. The route
 * carries the permission and the controller checks it; this is the reminder of
 * why.
 */
class DriverReport
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function performance(DateRange $range): array
    {
        $tasks = OrderTask::with('driver:id,name,phone')
            ->whereNotNull('driver_id')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $earnings = DriverEarning::whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('driver_id, coalesce(sum(amount), 0) total')
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        $grouped = [];

        foreach ($tasks as $task) {
            $key = $task->driver_id;

            // Read once into a variable: the relation is non-null by the query's
            // own whereNotNull, but a driver whose role changed would resolve to
            // null through the Driver scope, and a report should not fatal on a
            // single odd row.
            $driver = $task->driver;

            $grouped[$key] ??= [
                'driver' => $driver->name ?? '—',
                'phone' => $driver->phone ?? null,
                'tasks' => 0,
                'completed' => 0,
                'failed' => 0,
                'late' => 0,
                'durations' => [],
                'earnings' => round((float) ($earnings[$key] ?? 0), 2),
            ];

            $grouped[$key]['tasks']++;

            if ($task->status === TaskStatus::Completed) {
                $grouped[$key]['completed']++;

                $minutes = $task->durationMinutes();

                if ($minutes !== null) {
                    $grouped[$key]['durations'][] = $minutes;
                }

                // Lateness is judged on what was completed: an open task that is
                // merely running behind has not failed anybody yet.
                if ($task->due_at && $task->completed_at
                    && $task->completed_at->greaterThan($task->due_at)) {
                    $grouped[$key]['late']++;
                }
            }

            if ($task->status === TaskStatus::Failed) {
                $grouped[$key]['failed']++;
            }
        }

        $out = [];

        foreach ($grouped as $row) {
            $durations = $row['durations'];

            $out[] = [
                'driver' => $row['driver'],
                'phone' => $row['phone'],
                'tasks' => $row['tasks'],
                'completed' => $row['completed'],
                'failed' => $row['failed'],
                'late' => $row['late'],
                'earnings' => $row['earnings'],
                'completion_rate' => round($row['completed'] / $row['tasks'] * 100, 1),
                'late_rate' => $row['completed'] > 0
                    ? round($row['late'] / $row['completed'] * 100, 1)
                    : 0.0,
                // Null, not zero: a driver with nothing measured has no average.
                'average_minutes' => $durations === []
                    ? null
                    : (int) round(array_sum($durations) / count($durations)),
            ];
        }

        usort($out, fn ($a, $b) => $b['tasks'] <=> $a['tasks']);

        return $out;
    }

    /**
     * Why journeys failed.
     *
     * A breakdown rather than a count, because the reasons want different
     * responses: «العنوان غير صحيح» is a data problem, «العميل غير متاح» is a
     * scheduling one, and «عدد القطع غير مطابق» is neither.
     *
     * @return array<int, array<string, mixed>>
     */
    public function failureReasons(DateRange $range): array
    {
        $counts = OrderTask::whereNotNull('failure_reason')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('failure_reason, count(*) c')
            ->groupBy('failure_reason')
            ->pluck('c', 'failure_reason');

        $total = (int) $counts->sum();
        $out = [];

        foreach (TaskFailureReason::cases() as $reason) {
            $count = (int) ($counts[$reason->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $out[] = [
                'reason' => __($reason->label()),
                'count' => $count,
                'share' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }
}

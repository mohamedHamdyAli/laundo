<?php

namespace App\Modules\Report\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Report\Data\DateRange;
use App\Modules\Report\Services\DriverReport;
use App\Modules\Report\Services\LaundryReport;
use App\Modules\Report\Services\OperationsReport;
use App\Modules\Report\Services\OrderReport;
use App\Modules\Report\Services\RevenueReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports.
 *
 * Access works two different ways here, and the difference is worth stating:
 *
 *  - **Revenue, orders and laundry performance are tenant-scoped.** A laundry
 *    owner sees their own figures because `Order` is scoped, with no rule in this
 *    controller to get wrong.
 *  - **Driver performance and operations health are not.** Drivers work across
 *    laundries, so the scope would not stop one tenant seeing another's drivers.
 *    Those two carry their own permission, and the routes enforce it.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly RevenueReport $revenue,
        private readonly OrderReport $orders,
        private readonly LaundryReport $laundries,
        private readonly DriverReport $drivers,
        private readonly OperationsReport $operations,
    ) {}

    public function revenue(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $previous = $range->previous();

        $summary = $this->revenue->summary($range);

        return view('admin.report.revenue', [
            'range' => $range,
            'summary' => $summary,
            // What "up 12% on the previous period" is measured against.
            'previous' => $this->revenue->summary($previous),
            'daily' => $this->revenue->daily($range),
            'byLaundry' => $this->revenue->byLaundry($range),
            'byService' => $this->revenue->byService($range),
            'byMethod' => $this->revenue->byMethod($range),
        ]);
    }

    public function orders(Request $request)
    {
        $range = DateRange::fromRequest($request);

        return view('admin.report.orders', [
            'range' => $range,
            'summary' => $this->orders->summary($range),
            'daily' => $this->orders->daily($range),
            'byStatus' => $this->orders->byStatus($range),
            'byZone' => $this->orders->byZone($range),
            'priceMovement' => $this->orders->priceMovement($range),
        ]);
    }

    public function laundries(Request $request)
    {
        $range = DateRange::fromRequest($request);

        return view('admin.report.laundries', [
            'range' => $range,
            'rows' => $this->laundries->performance($range),
        ]);
    }

    public function drivers(Request $request)
    {
        $range = DateRange::fromRequest($request);

        return view('admin.report.drivers', [
            'range' => $range,
            'rows' => $this->drivers->performance($range),
            'failures' => $this->drivers->failureReasons($range),
        ]);
    }

    public function operations()
    {
        // Deliberately no range: this is the state of the business now, and a
        // stuck order from last month is more urgent than one from this morning,
        // not less.
        return view('admin.report.operations', [
            'snapshot' => $this->operations->snapshot(),
        ]);
    }

    /**
     * CSV, streamed rather than assembled in memory.
     *
     * A year of orders built into a string is a request that dies at the memory
     * limit on the day the business finally has enough data to want the export.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        $range = DateRange::fromRequest($request);

        [$headers, $rows] = match ($report) {
            'revenue' => [
                ['date', 'orders', 'total'],
                array_map(fn ($r) => [$r['date'], $r['orders'], $r['total']], $this->revenue->daily($range)),
            ],
            'orders' => [
                ['date', 'orders'],
                array_map(fn ($r) => [$r['date'], $r['orders']], $this->orders->daily($range)),
            ],
            'laundries' => [
                ['laundry', 'orders', 'completed', 'cancelled', 'disputed', 'revenue', 'avg_turnaround_hours'],
                array_map(fn ($r) => [
                    $r['laundry'], $r['orders'], $r['completed'], $r['cancelled'],
                    $r['disputed'], $r['revenue'], $r['average_turnaround_hours'],
                ], $this->laundries->performance($range)),
            ],
            'drivers' => [
                ['driver', 'tasks', 'completed', 'failed', 'late', 'completion_rate', 'avg_minutes', 'earnings'],
                array_map(fn ($r) => [
                    $r['driver'], $r['tasks'], $r['completed'], $r['failed'], $r['late'],
                    $r['completion_rate'], $r['average_minutes'], $r['earnings'],
                ], $this->drivers->performance($range)),
            ],
            default => abort(404),
        };

        $filename = "laundo-{$report}-{$range->from->toDateString()}-to-{$range->to->toDateString()}.csv";

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel opens Arabic as Arabic rather than as mojibake —
            // which is how most of these files will actually be read.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

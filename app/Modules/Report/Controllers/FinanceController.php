<?php

namespace App\Modules\Report\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Report\Services\FinanceSummary;
use Illuminate\Contracts\View\View;

/**
 * «ملخص الماليات» — the money the home page used to show, behind its own
 * permission (`finance.view`, route middleware).
 */
class FinanceController extends Controller
{
    public function index(FinanceSummary $finance): View
    {
        $isLaundry = $finance->isLaundryView();

        return view('admin.finance.index', [
            'isLaundry' => $isLaundry,
            'today' => $finance->today(),
            'month' => $finance->thisMonth(),
            'compared' => $finance->compared(),
            'split' => $finance->split(),
            'owed' => $finance->owed(),
            'byDay' => $finance->byDay(),
            'byMethod' => $finance->byMethod(),
            'byService' => $finance->top('service'),
            // A laundry would see a list of one — itself — so it is not shown.
            'byLaundry' => $isLaundry ? null : $finance->top('laundry'),
        ]);
    }
}

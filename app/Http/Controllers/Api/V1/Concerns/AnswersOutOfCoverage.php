<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Modules\Order\Services\OutOfCoverage;
use App\Modules\Zone\Services\coverageRequestCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «عذرًا، الخدمة غير متاحة حاليًا في منطقتك» — and a note of who asked.
 *
 * One answer for every endpoint that refuses an address in no zone (the quote,
 * the order, a repeat schedule), so the app meets the same `out_of_coverage`
 * wherever it asks. The app answers it with its own message, which promises to
 * contact the customer once the area is served; recording the attempt is what
 * lets somebody keep that promise («خارج التغطية»).
 *
 * Recorded here, after the service has let go — outside its transaction, so a
 * rollback cannot take the note with it — and never fatally (`record()`).
 */
trait AnswersOutOfCoverage
{
    protected function outOfCoverage(Request $request, OutOfCoverage $e): JsonResponse
    {
        $errors = [];

        foreach ($e->addresses as $field => $address) {
            app(coverageRequestCrudService::class)->record($request->user(), $address);
            $errors[$field] = [__('This address is outside the areas we serve.')];
        }

        return failReturnOutOfCoverage($errors, __('Sorry, the service is not available in your area yet.'));
    }
}

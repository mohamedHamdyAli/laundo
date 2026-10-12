<?php

namespace App\Modules\Driver\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Services\DriverMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «خريطة السواقين» — HTTP only; what is shown and to whom is `DriverMap`'s.
 */
class DriverMapController extends Controller
{
    public function __construct(private readonly DriverMap $map) {}

    public function index(Request $request)
    {
        $view = view('admin.driver_map.index', $this->map->shredData());

        return $request->ajax() ? response($view) : $view;
    }

    /**
     * What the open map asks for every few seconds.
     */
    public function data(): JsonResponse
    {
        return response()->json($this->map->snapshot());
    }

    /**
     * Who could take a waiting leg, nearest first. `{id}` is the task.
     */
    public function candidates($id): JsonResponse
    {
        $answer = $this->map->candidates((int) $id);

        abort_if($answer === null, 404, __('This leg is not waiting for a driver any more.'));

        return response()->json($answer);
    }
}

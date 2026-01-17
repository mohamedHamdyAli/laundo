<?php

namespace App\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResponseService
{
    public static function validationError(string $message = 'Error Occurred', $data = null)
    {
        self::errorResponse($message, $data, config('constants.RESPONSE_CODE.VALIDATION_ERROR'));
    }

    public static function errorResponse(string $message = 'Error Occurred', $data = null, string|int $code = null, $e = null): never
    {
        response()->json([
            'error' => true,
            'message' => trans($message),
            'data' => $data,
            'code' => $code ?? config('constants.RESPONSE_CODE.EXCEPTION_ERROR'),
            'details' => (!empty($e) && is_object($e)) ? $e->getMessage() . ' --> ' . $e->getFile() . ' At Line : ' . $e->getLine() : ''
        ])->send();
        exit();
    }

    public static function successResponse(string|null $message = "Success", $data = null, array $customData = [], $code = null): void
    {
        response()->json(array_merge([
            'error' => false,
            'message' => trans($message),
            'data' => $data,
            'code' => $code ?? config('constants.RESPONSE_CODE.SUCCESS')
        ], $customData))->send();
        exit();
    }
    public static function logErrorResponse(Throwable|Exception $e, string $logMessage = ' ', string $responseMessage = 'Error Occurred', bool $jsonResponse = true)
    {
        $userToken = request()->bearerToken() ?? '';
        Log::error($logMessage . ' ' . $e->getMessage() . '---> ' . $e->getFile() . ' At Line : ' . $e->getLine() . "\n\n" . request()->method() . " : " . request()->fullUrl() . "\nUser Token : " . $userToken . "\nParams : ", request()->all());
        if ($jsonResponse && config('app.debug')) {
            self::errorResponse($responseMessage, null, null, $e);
        }
    }

    /**
     * @param Model&object{status: string|int} $model
     */
    public function toggleStatus(Model $model, string|int $status): Model
    {
        $model->status = $status;
        $model->save();

        return $model;
    }


    public static function noPermissionThenSendJson($permission)
    {
        if (Auth::check() && Auth::user()->role->slug === 'super_admin') {
            // Admin skips permission checks
            return true;
        }

        if (!Auth::user()->can($permission)) {
            self::errorResponse("You Don't have enough permissions");
        }
        return true;
    }
    public static function noPermissionThenRedirect($permission)
    {
        if (Auth::check() && Auth::user()->role->slug === 'super_admin') {
            // Admin skips permission checks
            return true;
        }

        if (!Auth::user()->can($permission)) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions")
            ]);
        }

        return true;
    }
}

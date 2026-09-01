<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;

/*
|--------------------------------------------------------------------------
| API Response Envelope
|--------------------------------------------------------------------------
|
| Every API response is shaped:
|
|   {
|     "key":  "success" | "fail" | "not_auth" | "forbidden" | "not_found"
|            | "validation_error" | "throttled" | "server_error",
|     "msg":    "human readable, translated",
|     "code":   200,          // mirrors the HTTP status
|     "data":   mixed|null,   // present on success / data-carrying failures
|     "errors": {...},        // validation_error only
|     "meta":   {...}         // paginated responses only
|   }
|
| The envelope `code` and the HTTP status are always the same value, so a
| client may branch on either one. Keys come from config/constants.php.
|
*/

if (! function_exists('apiResponseCode')) {
    /**
     * Resolve a named response code from config, falling back to the literal.
     */
    function apiResponseCode(string $name, int $fallback): int
    {
        return (int) config("constants.RESPONSE_CODE.{$name}", $fallback);
    }
}

if (! function_exists('apiResponseKey')) {
    /**
     * Resolve a named envelope key from config, falling back to the literal.
     */
    function apiResponseKey(string $name, string $fallback): string
    {
        return (string) config("constants.RESPONSE_KEY.{$name}", $fallback);
    }
}

if (! function_exists('apiEnvelope')) {
    /**
     * Build the envelope and send it with a matching HTTP status.
     *
     * @param  array<string, mixed>  $extra
     */
    function apiEnvelope(string $key, int $code, string $msg = '', array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'key' => $key,
            'msg' => $msg !== '' ? trans($msg) : '',
            'code' => $code,
        ], $extra), $code);
    }
}

// ===================================================
// ===================== Success =====================
// ===================================================

if (! function_exists('successReturnData')) {
    /**
     * Success with a data payload.
     */
    function successReturnData($data = [], string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('SUCCESS', 'success'),
            apiResponseCode('SUCCESS', 200),
            $msg,
            ['data' => $data]
        );
    }
}

if (! function_exists('returnSuccessMsg')) {
    /**
     * Success with a message only.
     */
    function returnSuccessMsg(string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('SUCCESS', 'success'),
            apiResponseCode('SUCCESS', 200),
            $msg
        );
    }
}

if (! function_exists('successReturnCreated')) {
    /**
     * Success for a newly created resource.
     */
    function successReturnCreated($data = [], string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('SUCCESS', 'success'),
            apiResponseCode('CREATED', 201),
            $msg,
            ['data' => $data]
        );
    }
}

if (! function_exists('successReturnPaginated')) {
    /**
     * Success for a paginated list. Items land in `data`, page info in `meta`.
     */
    function successReturnPaginated($paginator, string $msg = ''): JsonResponse
    {
        $meta = [];

        if ($paginator instanceof Paginator) {
            $meta = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ];

            // Only length-aware paginators know the totals; simplePaginate() does not.
            if ($paginator instanceof LengthAwarePaginator) {
                $meta['total'] = $paginator->total();
                $meta['last_page'] = $paginator->lastPage();
            }
        }

        return apiEnvelope(
            apiResponseKey('SUCCESS', 'success'),
            apiResponseCode('SUCCESS', 200),
            $msg,
            [
                'data' => $paginator instanceof Paginator ? $paginator->items() : $paginator,
                'meta' => $meta,
            ]
        );
    }
}

// ===================================================
// ===================== Failure =====================
// ===================================================

if (! function_exists('failReturnMsg')) {
    /**
     * Generic client-side failure.
     */
    function failReturnMsg(string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('FAIL', 'fail'),
            apiResponseCode('BAD_REQUEST', 400),
            $msg
        );
    }
}

if (! function_exists('failReturnData')) {
    /**
     * Generic client-side failure carrying a payload.
     */
    function failReturnData($data = [], string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('FAIL', 'fail'),
            apiResponseCode('BAD_REQUEST', 400),
            $msg,
            ['data' => $data]
        );
    }
}

if (! function_exists('failReturnAuth')) {
    /**
     * Missing, expired or revoked token.
     */
    function failReturnAuth(string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('NOT_AUTH', 'not_auth'),
            apiResponseCode('NOT_AUTHENTICATED', 401),
            $msg !== '' ? $msg : 'Unauthenticated.'
        );
    }
}

if (! function_exists('failReturnForbidden')) {
    /**
     * Authenticated but not allowed.
     */
    function failReturnForbidden(string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('FORBIDDEN', 'forbidden'),
            apiResponseCode('FORBIDDEN', 403),
            $msg !== '' ? $msg : "You Don't have enough permissions"
        );
    }
}

if (! function_exists('failReturnNotFound')) {
    /**
     * Resource does not exist.
     */
    function failReturnNotFound(string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('NOT_FOUND', 'not_found'),
            apiResponseCode('NOT_FOUND', 404),
            // Not the `no_data_found` key used in Blade — an untranslated snake_case
            // key must never leak to an API consumer.
            $msg !== '' ? $msg : 'Resource not found.'
        );
    }
}

if (! function_exists('failReturnValidation')) {
    /**
     * Validation failure. `errors` is keyed by field name.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    function failReturnValidation(array $errors = [], string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('VALIDATION_ERROR', 'validation_error'),
            apiResponseCode('VALIDATION_ERROR', 422),
            $msg !== '' ? $msg : 'Invalid data send',
            ['errors' => $errors]
        );
    }
}

if (! function_exists('failReturnThrottled')) {
    /**
     * Rate limit exceeded.
     */
    function failReturnThrottled(?int $retryAfter = null, string $msg = ''): JsonResponse
    {
        return apiEnvelope(
            apiResponseKey('THROTTLED', 'throttled'),
            apiResponseCode('TOO_MANY_REQUESTS', 429),
            $msg !== '' ? $msg : 'Too many requests. Please try again later.',
            ['retry_after' => $retryAfter]
        );
    }
}

if (! function_exists('failReturnServer')) {
    /**
     * Unhandled server error. Diagnostics are exposed only when app.debug is on.
     */
    function failReturnServer(string $msg = '', ?Throwable $e = null): JsonResponse
    {
        $extra = [];

        if ($e !== null && config('app.debug')) {
            $extra['details'] = $e->getMessage().' --> '.$e->getFile().' At Line : '.$e->getLine();
        }

        return apiEnvelope(
            apiResponseKey('SERVER_ERROR', 'server_error'),
            apiResponseCode('EXCEPTION_ERROR', 500),
            $msg !== '' ? $msg : 'Error Occurred',
            $extra
        );
    }
}

if (! function_exists('invalidData')) {
    /**
     * Kept for backwards compatibility. Prefer failReturnValidation().
     *
     * @deprecated use failReturnValidation()
     */
    function invalidData($data = []): JsonResponse
    {
        return failReturnValidation(is_array($data) ? $data : [], 'Invalid data send');
    }
}

<?php

return [
    'CACHE' => [
        'LANGUAGE' => 'languages',
        'SETTINGS' => 'settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Response Codes
    |--------------------------------------------------------------------------
    |
    | Referenced by App\Services\ResponseService and app/Helpers/ApiResponse.php.
    | These mirror the HTTP status sent with each response so clients can read
    | either the envelope `code` or the HTTP status and get the same answer.
    |
    */
    'RESPONSE_CODE' => [
        'SUCCESS' => 200,
        'CREATED' => 201,
        'BAD_REQUEST' => 400,
        'NOT_AUTHENTICATED' => 401,
        'FORBIDDEN' => 403,
        'NOT_FOUND' => 404,
        'VALIDATION_ERROR' => 422,
        'TOO_MANY_REQUESTS' => 429,
        'EXCEPTION_ERROR' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Response Envelope Keys
    |--------------------------------------------------------------------------
    |
    | The `key` field of every API response. Clients branch on this.
    |
    */
    'RESPONSE_KEY' => [
        'SUCCESS' => 'success',
        'FAIL' => 'fail',
        'NOT_AUTH' => 'not_auth',
        'FORBIDDEN' => 'forbidden',
        'NOT_FOUND' => 'not_found',
        'VALIDATION_ERROR' => 'validation_error',
        'THROTTLED' => 'throttled',
        'SERVER_ERROR' => 'server_error',
    ],
];

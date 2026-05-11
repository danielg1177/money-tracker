<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plaid API credentials (Dashboard → Keys)
    |--------------------------------------------------------------------------
    */
    'client_id' => env('PLAID_CLIENT_ID'),
    'secret' => env('PLAID_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Environment: sandbox | development | production
    |--------------------------------------------------------------------------
    */
    'env' => env('PLAID_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | HTTP API base URL (overridden automatically by env unless set)
    |--------------------------------------------------------------------------
    */
    'base_url' => env('PLAID_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Plaid-Version header (must be a released date from Plaid's versioning docs)
    |--------------------------------------------------------------------------
    */
    'api_version' => env('PLAID_API_VERSION', '2020-09-14'),

    /*
    |--------------------------------------------------------------------------
    | History window requested when creating a Link token (max varies by institution)
    |--------------------------------------------------------------------------
    */
    'transactions_days_requested' => (int) env('PLAID_TRANSACTIONS_DAYS_REQUESTED', 90),

];

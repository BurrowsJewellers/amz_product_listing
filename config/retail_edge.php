<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Duration in Minutes
    |--------------------------------------------------------------------------
    |
    | This value determines how long to cache the RetailEdge data before
    | fetching fresh data from the API.
    |
    */
    'cache_minutes' => env('RETAIL_EDGE_CACHE_MINUTES', 20),
];

<?php

return [
    /*
     * Whether to cache Signal results. When enabled, repeated calls with the
     * same query will return cached data until the TTL expires.
     */
    'cache' => [
        'enabled' => false,
        'ttl' => 60, // seconds
    ],

    /*
     * Default polling interval in milliseconds for Alpine.js / Livewire polling.
     * Pass as meta so the front-end can wire it up without hard-coding values.
     */
    'polling_interval' => 2000,

    /*
     * When true, Signal::getData() returns an Illuminate Collection.
     * When false, it returns a plain array.
     */
    'as_collection' => true,

    /*
     * Maximum number of rows a Signal may hold. Prevents accidentally
     * serializing huge result sets through Livewire's JSON cycle.
     * Set to null to disable the limit.
     */
    'max_rows' => 1000,
];

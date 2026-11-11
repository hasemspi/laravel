<?php

return [
    'ads' => [
        'enabled' => (bool) env('BLOG_ADS_ENABLED', false),
        'client' => env('ADSENSE_CLIENT_ID'),
        'slots' => [
            'featured' => env('BLOG_AD_SLOT_FEATURED'),
            'content' => env('BLOG_AD_SLOT_CONTENT'),
            'sidebar' => env('BLOG_AD_SLOT_SIDEBAR'),
        ],
    ],
];

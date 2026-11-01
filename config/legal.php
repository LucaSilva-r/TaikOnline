<?php

return [
    // The Rules players must accept. Unset, nothing is asked and the pages are off; a new value asks everyone again.
    'version' => env('LEGAL_VERSION'),

    // This instance's rules.md and privacy.md, its own text: resources/legal has templates to start from.
    'path' => env('LEGAL_PATH', storage_path('legal')),
];

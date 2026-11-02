<?php

return [
    // The Rules players must accept. Unset, nothing is asked and the pages are off; a new value asks everyone again.
    'version' => env('LEGAL_VERSION'),

    // This instance's rules.md and privacy.md, its own text: resources/legal has templates to start from.
    'path' => env('LEGAL_PATH', storage_path('legal')),

    // Each version's documents as they were, kept the first time that version is served, to show what changed since.
    'history' => env('LEGAL_HISTORY_PATH', storage_path('app/legal-history')),
];

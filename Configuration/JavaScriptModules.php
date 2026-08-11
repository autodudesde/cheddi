<?php

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@autodudes/cheddi/' => 'EXT:cheddi/Resources/Public/JavaScript/',
        // Locally vendored to keep the chat self-contained. Versions verified
        // against published CVE advisories before vendoring:
        //   marked 18.0.3 (patches CVE-2026-41680)
        //   DOMPurify 3.4.2 (patches CVE-2026-0540 + CVE-2026-41238)
        // When bumping, re-run the audit and update this comment.
        'marked' => 'EXT:cheddi/Resources/Public/JavaScript/vendor/marked.esm.js',
        'dompurify' => 'EXT:cheddi/Resources/Public/JavaScript/vendor/dompurify.es.mjs',
    ],
];

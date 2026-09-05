<?php

declare(strict_types=1);

// Explicit non-sensitive code egress allowlist. No memory, sensors or review notes.
return [
    'profile' => 'public-reflection',
    'model' => 'opencode/muse-spark-1.3-contributor-free',
    'sources' => [
        'src/Core/BackgroundStateCompiler.php' => '44d73ab06c0bc8a2ea25ea8b86f37c938bc6918c9aaba55f1d8f3860ef2afb31',
        'src/Core/NarrativeSynthesis.php' => '3fd7e0d4d330660aa1be710bed593ce140ebf2d48eede2e08e1f91ab458a4dc6',
    ],
    // Hashes independently matched unauthenticated public GitHub downloads.
    'public_revision' => 'https://github.com/hparadiz/Navi-Brain/tree/bea0064f80d32e9845395d285efdd2de4a3e762e',
    'question' => 'Can unchanged or stale evidence repeatedly produce new-looking cognition? '
        . 'Identify one concrete failure in the supplied implementation, quote the relevant code, '
        . 'explain the causal path, and propose a falsifiable verification. '
        . 'Do not infer behavior of functions whose implementation is not supplied. '
        . 'If evidence is insufficient, state what is missing instead of inventing a finding.',
];

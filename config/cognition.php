<?php

declare(strict_types=1);

// Explicit non-sensitive code egress allowlist. No memory, sensors or review notes.
return [
    'profile' => 'public-reflection',
    'model' => 'opencode/muse-spark-1.3-contributor-free',
    'sources' => [
        'src/Core/BackgroundStateCompiler.php' => '44d73ab06c0bc8a2ea25ea8b86f37c938bc6918c9aaba55f1d8f3860ef2afb31',
        'src/Core/NarrativeSynthesis.php' => '4704c1985178179f3f2b47c41abd84e3c713379139eabacf07dee384a0bc7d74',
    ],
    // Hashes independently matched unauthenticated public GitHub downloads.
    'public_revision' => 'https://github.com/hparadiz/Navi-Brain/tree/5de17b11ab550ed7a5a519a081d7fadc00874171',
    'question' => 'Can unchanged or stale evidence repeatedly produce new-looking cognition? '
        . 'Identify one concrete failure in the supplied implementation, quote the relevant code, '
        . 'explain the causal path, and propose a falsifiable verification. '
        . 'Do not infer behavior of functions whose implementation is not supplied. '
        . 'If evidence is insufficient, state what is missing instead of inventing a finding.',
];

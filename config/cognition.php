<?php

declare(strict_types=1);

return [
    // Local upkeep plus Aku's selected free Muse consolidation lane.
    'profile' => 'dream-cycle',
    'native' => [
        'interval_seconds' => 30,
        'error_backoff_seconds' => 60,
        'slot_limit' => 32,
        'recovery_limit' => 16,
    ],
    // Selecting dream-cycle keeps native upkeep and adds this optional lane.
    // The pinned free offers may use submitted data to improve their models.
    'dream' => [
        'enabled' => true,
        'allow_private_memory' => false,
        'model' => 'muse-spark-1.3-contributor-free',
        'interval_seconds' => 5400,
        'max_prompts_per_cycle' => 1,
        'source_limit' => 32,
    ],

    // Used only when profile is explicitly set to public-reflection.
    // Non-sensitive code egress allowlist: no memory, sensors or review notes.
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

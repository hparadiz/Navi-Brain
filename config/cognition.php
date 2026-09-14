<?php

declare(strict_types=1);

return [

    'profile' => 'dream-cycle',
    'native' => [
        'interval_seconds' => 30,
        'error_backoff_seconds' => 60,
        'slot_limit' => 32,
        'recovery_limit' => 16,
    ],

    'dream' => [
        'enabled' => true,
        'allow_private_memory' => false,
        'model' => 'muse-spark-1.3-contributor-free',
        'interval_seconds' => 5400,
        'max_prompts_per_cycle' => 1,
        'source_limit' => 32,
    ],

    'model' => 'opencode/muse-spark-1.3-contributor-free',
    'sources' => [
        'src/Core/BackgroundStateCompiler.php' => '44d73ab06c0bc8a2ea25ea8b86f37c938bc6918c9aaba55f1d8f3860ef2afb31',
        'src/Core/NarrativeSynthesis.php' => '3fd7e0d4d330660aa1be710bed593ce140ebf2d48eede2e08e1f91ab458a4dc6',
    ],

    'public_revision' => 'https://github.com/hparadiz/Navi-Brain/tree/bea0064f80d32e9845395d285efdd2de4a3e762e',
    'question' => 'Can unchanged or stale evidence repeatedly produce new-looking cognition? '
        . 'Identify one concrete failure in the supplied implementation, quote the relevant code, '
        . 'explain the causal path, and propose a falsifiable verification. '
        . 'Do not infer behavior of functions whose implementation is not supplied. '
        . 'If evidence is insufficient, state what is missing instead of inventing a finding.',
];

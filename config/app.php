<?php

declare(strict_types=1);

$environment = require __DIR__ . '/environment.php';
$identityName = trim((string) ($environment['Name'] ?? 'Navi'));
$name = trim((string) ($environment['NAVI_USER_NAME'] ?? 'User'));
$name = $name === '' ? 'User' : $name;
$pronunciation = trim((string) ($environment['NAVI_USER_NAME_PRONUNCIATION'] ?? ''));

return [
    'environment' => 'dev',
    'name' => $identityName === '' ? 'Navi' : $identityName,
    'pronouns' => [
        'subject' => trim((string) ($environment['PronounSubject'] ?? 'she')) ?: 'she',
        'object' => trim((string) ($environment['PronounObject'] ?? 'her')) ?: 'her',
        'reflexive' => trim((string) ($environment['PronounReflexive'] ?? 'herself')) ?: 'herself',
    ],
    'user_name' => $name,
    'user_name_pronunciation' => $pronunciation === '' ? $name : $pronunciation,
];

<?php

/**
 * Конфигурация бота.
 *
 * BOT_TOKEN    - токен бота из @BotFather (обязателен)
 * BOT_DATA_DIR - каталог с данными чатов (по умолчанию data/ в корне проекта)
 * BOT_TIMEZONE - таймзона для "сегодня/завтра" (по умолчанию Europe/Moscow)
 * POLL_TIMEOUT - таймаут long polling в секундах (по умолчанию 30)
 */

$rootDir = dirname(__DIR__);

$dataDir = getenv('BOT_DATA_DIR') ?: $rootDir . '/data';
$timezone = getenv('BOT_TIMEZONE') ?: 'Europe/Moscow';

date_default_timezone_set($timezone);

return [
    'token' => getenv('BOT_TOKEN') ?: '',
    'data_dir' => $dataDir,
    'timezone' => $timezone,
    'poll_timeout' => (int) (getenv('POLL_TIMEOUT') ?: 30),
    'version' => '1.0.0',
];

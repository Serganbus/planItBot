<?php

declare(strict_types=1);

use PlannerBot\BotKernel;
use PlannerBot\Infrastructure\ChatContextFactory;
use TelegramBot\Api\Client;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Зависимости не установлены. Выполните: composer install\n");
    exit(1);
}
require $autoload;

// Библиотека telegram-bot/api выдаёт E_DEPRECATED на PHP >= 8.4 — скрываем только deprecation.
error_reporting(E_ALL & ~E_DEPRECATED);

$config = require __DIR__ . '/../config/config.php';

if ($config['token'] === '') {
    fwrite(STDERR, "Не задан BOT_TOKEN. Установите переменную окружения BOT_TOKEN с токеном от @BotFather.\n");
    exit(1);
}

$kernel = new BotKernel(
    new Client($config['token']),
    new ChatContextFactory($config['data_dir']),
    $config['timezone'],
    $config['poll_timeout'],
);

try {
    $kernel->run();
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . "\n");
    exit(1);
}

<?php

declare(strict_types=1);

namespace App\Telegraph\Bots;

use DefStudio\Telegraph\Telegraph;

/**
 * Клиент Telegram Bot API для бота планировщика (`telegraph.bots.main`).
 *
 * Наследует весь fluent-API telegraph (message/html/keyboard/...).
 * Сюда добавляются только bot-specific расширения транспорта;
 * бизнес-логика живёт в доменных сервисах (app/Domain, с Шага 3),
 * маршрутизация апдейтов — в app/Application/Telegram (с Шага 5).
 */
class PlannerBot extends Telegraph
{
}

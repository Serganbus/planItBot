<?php

declare(strict_types=1);

use App\Support\DateParser;
use App\Support\PriorityParser;
use App\Support\TagParser;
use App\Support\TimeParser;

beforeEach(function (): void {
    $this->dates = new DateParser(new DateTimeZone('Europe/Moscow'));
});

it('parses relative date words', function (): void {
    $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');

    expect($this->dates->parse('сегодня'))->toBe($today)
        ->and($this->dates->parse('today'))->toBe($today)
        ->and($this->dates->parse('завтра'))->toBe($this->dates->shift($today, 1))
        ->and($this->dates->parse('вчера'))->toBe($this->dates->shift($today, -1))
        ->and($this->dates->parse('+3'))->toBe($this->dates->shift($today, 3));
});

it('parses absolute date formats', function (): void {
    expect($this->dates->parse('2026-02-28'))->toBe('2026-02-28')
        ->and($this->dates->parse('28.02.2026'))->toBe('2026-02-28')
        ->and($this->dates->parse('5.2.2026'))->toBe('2026-02-05');
});

it('rejects invalid dates', function (?string $input): void {
    expect($this->dates->parse((string) $input))->toBeNull();
})->with(['', '   ', '32.01.2026', '2026-13-01', 'послезавтра', '26/02/2026']);

it('parses time in HH:MM', function (): void {
    expect(TimeParser::parse('9:30'))->toBe('09:30')
        ->and(TimeParser::parse('23.45'))->toBe('23:45')
        ->and(TimeParser::parse(' 00:05 '))->toBe('00:05')
        ->and(TimeParser::parse('24:00'))->toBeNull()
        ->and(TimeParser::parse('9:60'))->toBeNull()
        ->and(TimeParser::parse('девять'))->toBeNull()
        ->and(TimeParser::isValidRange('09:00', '18:00'))->toBeTrue()
        ->and(TimeParser::isValidRange('18:00', '09:00'))->toBeFalse();
});

it('parses priority with russian synonyms and maps to int', function (): void {
    expect(PriorityParser::parse('Высокий'))->toBe('high')
        ->and(PriorityParser::parse('m'))->toBe('medium')
        ->and(PriorityParser::parse('низ'))->toBe('low')
        ->and(PriorityParser::parse('срочно'))->toBeNull()
        ->and(PriorityParser::toInt('высокий'))->toBe(3)
        ->and(PriorityParser::toInt(null))->toBe(0)
        ->and(PriorityParser::toInt(''))->toBe(0)
        ->and(PriorityParser::name(2))->toBe('medium')
        ->and(PriorityParser::label(3))->toBe('высокий');

    PriorityParser::toInt('zzz');
})->throws(InvalidArgumentException::class);

it('parses and formats tags', function (): void {
    expect(TagParser::parseMany('#Работа, #Личное #работа #it_department'))->toBe(['работа', 'личное', 'it_department'])
        ->and(TagParser::normalize('#Тег'))->toBe('тег')
        ->and(TagParser::parseMany('без тегов'))->toBe([])
        ->and(TagParser::format(['работа', 'спорт']))->toBe('#работа #спорт');
});

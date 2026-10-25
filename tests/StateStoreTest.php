<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use PlannerBot\State\StateStore;
use PHPUnit\Framework\TestCase;

final class StateStoreTest extends TestCase
{
    private string $dir;
    private StateStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/planner-bot-state-' . uniqid();
        $this->store = new StateStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testDefaultsWhenFileMissing(): void
    {
        $state = $this->store->get();

        self::assertNull($state['flow']);
        self::assertNull($state['step']);
        self::assertSame([], $state['payload']);
        self::assertSame('', $state['day']);
        self::assertNull($this->store->getDay());
    }

    public function testStartFlowAndClear(): void
    {
        $this->store->startFlow('add_item', 'start', ['date' => '2026-10-24']);

        $store2 = new StateStore($this->dir);
        $state = $store2->get();

        self::assertSame('add_item', $state['flow']);
        self::assertSame('start', $state['step']);
        self::assertSame(['date' => '2026-10-24'], $state['payload']);

        $store2->clearFlow();
        $state = (new StateStore($this->dir))->get();
        self::assertNull($state['flow']);
        self::assertSame([], $state['payload']);
    }

    public function testDayPersistsAcrossFlowChanges(): void
    {
        $this->store->setDay('2026-10-24');
        $this->store->startFlow('add_task', 'title', ['date' => '2026-10-24']);
        $this->store->clearFlow();

        self::assertSame('2026-10-24', (new StateStore($this->dir))->getDay());
    }
}

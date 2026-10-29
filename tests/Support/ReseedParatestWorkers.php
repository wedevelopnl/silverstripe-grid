<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Support;

use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Gives every paratest worker its own mt_rand() stream.
 *
 * Under executionOrder="random", paratest calls mt_srand() with the one shared
 * seed in every worker before each test class. SilverStripe names a temp
 * database time() + rand(), so two workers building one in the same second
 * draw the same name: the later one either fails CREATE DATABASE or, when the
 * database already exists by the time it selects it, silently shares the other
 * worker's database. Reseeding after the worker's own mt_srand() leaves the
 * class order alone — paratest shuffles that in its main process.
 */
final class ReseedParatestWorkers implements Extension, ExecutionStartedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (\getenv('TEST_TOKEN') !== false) {
            $facade->registerSubscriber($this);
        }
    }

    public function notify(ExecutionStarted $event): void
    {
        \mt_srand();
    }
}

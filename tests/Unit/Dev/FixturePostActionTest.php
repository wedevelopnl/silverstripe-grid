<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Dev;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Dev\FixturePostAction;

#[CoversClass(FixturePostAction::class)]
final class FixturePostActionTest extends TestCase
{
    public function testFromConfigCreatesAction(): void
    {
        $action = FixturePostAction::fromConfig([
            'action' => 'publish_recursive',
            'class' => 'Page',
            'identifier' => 'e2e_page',
        ]);

        self::assertSame('publish_recursive', $action->action);
        self::assertSame('Page', $action->class);
        self::assertSame('e2e_page', $action->identifier);
    }

    public function testFromConfigThrowsForMissingKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FixturePostAction::fromConfig([
            'action' => 'publish_recursive',
            // missing 'class' and 'identifier'
        ]);
    }

    public function testFromConfigThrowsForUnknownAction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FixturePostAction::fromConfig([
            'action' => 'nuke_from_orbit',
            'class' => 'Page',
            'identifier' => 'e2e_page',
        ]);
    }

    public function testFromConfigIncludesFields(): void
    {
        $action = FixturePostAction::fromConfig([
            'action' => 'modify',
            'class' => 'Page',
            'identifier' => 'e2e_page',
            'fields' => ['Title' => 'Updated Title'],
        ]);

        self::assertSame(['Title' => 'Updated Title'], $action->fields);
    }

    public function testFromConfigDefaultsFieldsToEmptyArray(): void
    {
        $action = FixturePostAction::fromConfig([
            'action' => 'publish_recursive',
            'class' => 'Page',
            'identifier' => 'e2e_page',
        ]);

        self::assertSame([], $action->fields);
    }
}

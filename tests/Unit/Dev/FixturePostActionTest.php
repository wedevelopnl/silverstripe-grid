<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Dev;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Each case omits exactly ONE required key, leaving the other two valid, so
     * each arm of the `action || class || identifier` guard is independently the
     * sole failing condition. Omitting two keys at once lets a `||`→`&&` mutant on
     * one arm survive (a still-failing sibling arm masks it).
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function missingRequiredKeyProvider(): iterable
    {
        yield 'missing action' => [['class' => 'Page', 'identifier' => 'e2e_page']];
        yield 'missing class' => [['action' => 'publish_recursive', 'identifier' => 'e2e_page']];
        yield 'missing identifier' => [['action' => 'publish_recursive', 'class' => 'Page']];
    }

    /**
     * @param array<string, string> $config
     */
    #[DataProvider('missingRequiredKeyProvider')]
    public function testFromConfigThrowsWhenAnyRequiredKeyMissing(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "action", "class", and "identifier" keys');

        /** @var array{action?: string, class?: class-string, identifier?: string} $config */
        FixturePostAction::fromConfig($config);
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

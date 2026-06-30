<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBEnum;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Guards the native DB Enum columns declared in BlockMediaExtension against
 * drift from their backing PHP value enums.
 *
 * The Enum member list is a hardcoded string in $db — PHP cannot compute it in
 * a static property — so adding a case to MediaPosition/AspectRatio/
 * VerticalAlignment without widening the matching column would silently make
 * the new value unstorable (MySQL coerces out-of-range Enum writes to '').
 * This test fails the moment the column and its enum diverge.
 *
 * Config-only: it reads the field definition, no database round-trip needed.
 */
#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaEnumParityTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testMediaPositionColumnMatchesEnum(): void
    {
        $this->assertEnumColumnMatches('MediaPosition', array_column(MediaPosition::cases(), 'value'));
    }

    public function testMediaRatioColumnMatchesAspectRatioEnum(): void
    {
        $this->assertEnumColumnMatches('MediaRatio', array_column(AspectRatio::cases(), 'value'));
    }

    public function testVerticalAlignmentColumnMatchesEnum(): void
    {
        $this->assertEnumColumnMatches('VerticalAlignment', array_column(VerticalAlignment::cases(), 'value'));
    }

    /**
     * @param list<string> $expected
     */
    private function assertEnumColumnMatches(string $field, array $expected): void
    {
        $dbField = ContentElement::singleton()->dbObject($field);

        self::assertInstanceOf(
            DBEnum::class,
            $dbField,
            sprintf('%s must be declared as a native DB Enum so values are constrained at the schema level', $field),
        );

        sort($expected);
        $actual = array_values($dbField->getEnum());
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            sprintf('DB Enum members for %s have drifted from their PHP value enum; update BlockMediaExtension::$db', $field),
        );
    }
}

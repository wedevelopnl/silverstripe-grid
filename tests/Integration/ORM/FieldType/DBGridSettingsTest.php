<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\ORM\FieldType;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\ORM\FieldType\DBGridSettings;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(DBGridSettings::class)]
final class DBGridSettingsTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testRoundTripViaColumnWrite(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $original = new GridSettings(
            new ViewportConfig(8, 2, true),
            ['lg' => new ViewportConfig(6, 1, false)],
        );
        $column = GridTreeFactory::column($row, gridSettings: $original);

        // Reload from DB
        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        $settings = $reloaded->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);
        self::assertArrayHasKey('lg', $settings->overrides);
        self::assertSame(6, $settings->overrides['lg']->width);
        self::assertSame(1, $settings->overrides['lg']->offset);
        self::assertFalse($settings->overrides['lg']->visible);

        // Verify raw sub-field values match
        /** @var DBGridSettings $dbField */
        $dbField = $reloaded->dbObject('GridSettings');
        self::assertSame(8, $dbField->getField('DefaultWidth'));
        self::assertSame(2, $dbField->getField('DefaultOffset'));
    }

    public function testSetValueWithJsonString(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $json = '{"default":{"width":8,"offset":1,"visible":true},"overrides":{}}';
        $column->setGridSettings($json);
        $column->write();

        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        $settings = $reloaded->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(1, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }

    public function testSetValueWithInvalidString(): void
    {
        $field = DBGridSettings::create('GridSettings');
        $field->setValue('not-json');

        self::assertNull($field->getValue());
    }

    /**
     * A structurally-malformed JSON payload (valid JSON, but an override entry
     * missing a required key) is swallowed and coerced to null so a bad
     * legacy/fixture row does not crash reads — but the suppression must be
     * observable, so a warning is logged via the Injector-bound logger.
     */
    public function testSetValueWithMalformedJsonLogsWarningAndCoercesToNull(): void
    {
        $logger = new class () extends NullLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };
        Injector::inst()->registerService($logger, LoggerInterface::class);

        // Valid JSON, but the "default" object is missing required keys —
        // ViewportConfig::fromArray throws InvalidGridValueException.
        $malformed = '{"default":{"width":6},"overrides":{}}';

        $field = DBGridSettings::create('GridSettings');
        $field->setValue($malformed);

        self::assertNull($field->getValue(), 'malformed JSON must coerce to null, not crash');
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('DBGridSettings discarded malformed JSON', $logger->warnings[0]);
    }

    /**
     * A structurally-malformed stored `Overrides` column (valid JSON, but an
     * override entry missing a required key) must not crash the read path. The
     * default still decodes, overrides degrade to empty, and the suppression is
     * observable via a logged warning — symmetric with the write-side
     * malformed-JSON handling.
     */
    public function testGetValueWithMalformedOverridesColumnLogsWarningAndDegrades(): void
    {
        $logger = new class () extends NullLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };
        Injector::inst()->registerService($logger, LoggerInterface::class);

        $field = new DBGridSettings('Settings');
        $field->setField('DefaultWidth', 6);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);
        // Valid JSON, but the "md" override is missing the required "width" key —
        // ViewportConfig::fromArray throws InvalidGridValueException on decode.
        $field->setField('Overrides', json_encode([
            'md' => ['offset' => 1, 'visible' => true],
        ]));

        $value = $field->getValue();

        self::assertNotNull($value, 'malformed overrides must not crash the read path');
        self::assertSame(6, $value->default->width, 'the valid default must still decode');
        self::assertSame([], $value->overrides, 'malformed overrides degrade to empty');
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('DBGridSettings discarded malformed overrides column', $logger->warnings[0]);
    }

    public function testGetValueWhenNoData(): void
    {
        $field = DBGridSettings::create('GridSettings');

        self::assertNull($field->getValue());
    }

    public function testExistsWhenWidthStored(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        // Column gets initial GridSettings on first write
        /** @var DBGridSettings $dbField */
        $dbField = $column->dbObject('GridSettings');

        self::assertTrue($dbField->exists());
    }

    public function testExistsWhenNoWidth(): void
    {
        $field = DBGridSettings::create('GridSettings');

        self::assertFalse($field->exists());
    }

    public function testOverridesStoredAsJson(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['md' => new ViewportConfig(6, 0, true)],
        );
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        /** @var DBGridSettings $dbField */
        $dbField = $reloaded->dbObject('GridSettings');
        $rawOverrides = $dbField->getField('Overrides');

        self::assertNotNull($rawOverrides);
        self::assertIsString($rawOverrides);
        self::assertJson($rawOverrides);
    }

    public function testOverridesNullWhenEmpty(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            new ViewportConfig(12, 0, true),
        );
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        /** @var DBGridSettings $dbField */
        $dbField = $reloaded->dbObject('GridSettings');

        self::assertNull($dbField->getField('Overrides'));
    }

    public function testFieldValidationBlocksExcessiveWidth(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $this->expectException(ValidationException::class);

        $column->setGridSettings(new GridSettings(
            new ViewportConfig(13, 0, true),
        ));
        $column->write();
    }

    public function testFieldValidationBlocksExcessiveOffset(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $this->expectException(ValidationException::class);

        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 12, true),
        ));
        $column->write();
    }

    public function testFieldValidationBlocksWidthPlusOffset(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $this->expectException(ValidationException::class);

        $column->setGridSettings(new GridSettings(
            new ViewportConfig(8, 6, true),
        ));
        $column->write();
    }

    public function testGetColumnCountFromAdapter(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        /** @var DBGridSettings $dbField */
        $dbField = $column->dbObject('GridSettings');

        self::assertSame(12, $dbField->getColumnCount());
    }

    public function testScaffoldFormFieldReturnsNull(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        /** @var DBGridSettings $field */
        $field = $column->dbObject('GridSettings');

        self::assertNull($field->scaffoldFormField());
    }

    public function testSetValueWithGridSettingsAndRecord(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        /** @var DBGridSettings $field */
        $field = $column->dbObject('GridSettings');

        $settings = new GridSettings(new ViewportConfig(8, 1, true), []);
        $field->setValue($settings, $column);

        $result = $field->getValue();
        self::assertInstanceOf(GridSettings::class, $result);
        self::assertSame(8, $result->default->width);
        self::assertSame(1, $result->default->offset);
        self::assertTrue($result->default->visible);
    }

    public function testSetValueWithInvalidStringDoesNotSetSubFields(): void
    {
        // Create a fresh composite field not bound to a record
        $dbField = DBGridSettings::create('GridSettings');

        // Invalid JSON falls through to parent::setValue(null) — sub-fields not populated
        $dbField->setValue('not-valid-json');

        self::assertNull($dbField->getValue());
    }

    public function testGetValueDefaultOffsetIsZero(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $settings = $column->getGridSettings();
        self::assertNotNull($settings);
        self::assertSame(0, $settings->default->offset);
    }

    /**
     * A stored width of 0 is a legitimately-persisted value, not "no data".
     * getValue() must surface it (rejecting an out-of-range width is the
     * validator's job at write time) instead of silently dropping the VO.
     */
    public function testGetValuePreservesStoredZeroWidth(): void
    {
        $field = new DBGridSettings('Settings');
        $field->setField('DefaultWidth', 0);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);

        $value = $field->getValue();

        self::assertNotNull($value, 'stored width=0 must not be conflated with missing data');
        self::assertSame(0, $value->default->width);
    }

    /**
     * A row with width=0 plus non-empty overrides must NOT be silently dropped —
     * the previous `$width < 1` guard discarded the entire VO including overrides.
     */
    public function testGetValueWithZeroWidthDoesNotDropOverrides(): void
    {
        $field = new DBGridSettings('Settings');
        $field->setField('DefaultWidth', 0);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);
        $field->setField('Overrides', json_encode([
            'md' => ['width' => 6, 'offset' => 1, 'visible' => false],
        ]));

        $value = $field->getValue();

        self::assertNotNull($value);
        self::assertArrayHasKey('md', $value->overrides);
        self::assertSame(6, $value->overrides['md']->width);
    }

    /**
     * exists() and getValue() must agree on presence: a stored width=0 makes
     * the field exist, and getValue() must therefore return a non-null VO.
     */
    public function testExistsAndGetValueAgreeOnZeroWidth(): void
    {
        $field = new DBGridSettings('Settings');
        $field->setField('DefaultWidth', 0);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);

        self::assertTrue($field->exists());
        self::assertNotNull($field->getValue());
    }

    /**
     * exists() and getValue() must agree on absence: with no stored width the
     * field does not exist and getValue() returns null.
     */
    public function testExistsAndGetValueAgreeWhenNoWidth(): void
    {
        $field = new DBGridSettings('Settings');

        self::assertFalse($field->exists());
        self::assertNull($field->getValue());
    }

    public function testGetValueDefaultVisibleIsTrue(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $settings = $column->getGridSettings();
        self::assertNotNull($settings);
        self::assertTrue($settings->default->visible);
    }

    /**
     * width=1 is a valid minimum. Pins `$width < 1` at line 59 — if mutated to
     * `<= 1`, width=1 would be falsely treated as "missing data" and the field
     * would return null.
     */
    public function testGetValueWithMinimumWidthOfOne(): void
    {
        $field = new DBGridSettings('Settings');
        $field->setField('DefaultWidth', 1);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);

        $value = $field->getValue();

        self::assertNotNull($value, 'width=1 must be treated as valid data');
        self::assertSame(1, $value->default->width);
    }

    /**
     * When DefaultVisible is explicitly false in the DB, getValue must preserve it.
     * Pins both the `(bool) (... ?? true)` Coalesce/TrueValue variants — mutants
     * that replace the fallback or swap operands would always yield true.
     */
    public function testGetValuePreservesExplicitFalseVisible(): void
    {
        $field = new DBGridSettings('Settings');
        $field->setField('DefaultWidth', 6);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', false);

        $value = $field->getValue();

        self::assertNotNull($value);
        self::assertFalse($value->default->visible, 'stored false must not be masked by the ?? true fallback');
    }
}

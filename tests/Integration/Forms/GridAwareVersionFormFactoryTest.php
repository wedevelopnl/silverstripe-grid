<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use WeDevelop\Grid\Forms\GridAwareVersionFormFactory;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Model\GridElement;

#[CoversClass(GridAwareVersionFormFactory::class)]
final class GridAwareVersionFormFactoryTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testStripRemovesPlainGridFieldButKeepsGridEditorField(): void
    {
        $plain = GridField::create('Plain', 'Plain', GridElement::get());
        $editor = new GridEditorField('GridEditor', 42);

        $fields = FieldList::create($plain, $editor);

        $factory = new GridAwareVersionFormFactory();
        $method = new \ReflectionMethod($factory, 'removeGridFieldsExceptGridEditor');
        $method->invoke($factory, $fields);

        self::assertNull($fields->dataFieldByName('Plain'), 'plain GridField is stripped');
        self::assertSame($editor, $fields->dataFieldByName('GridEditor'), 'GridEditorField survives');
    }
}

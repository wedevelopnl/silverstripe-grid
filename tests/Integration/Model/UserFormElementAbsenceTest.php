<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DropdownField;
use SilverStripe\UserForms\UserForm;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Reports\GridElementReport;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\UserForms\UserFormElement;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Without userforms the form element file declares an inert stand-in, so the
 * framework's unguarded manifest loops can instantiate the class. It must
 * never surface: not offered, not creatable, not listed, not rendered.
 */
#[CoversClass(ContainerType::class)]
#[CoversClass(GridElementReport::class)]
#[CoversClass(UserFormElement::class)]
final class UserFormElementAbsenceTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        if (trait_exists(UserForm::class)) {
            self::markTestSkipped('userforms is installed; this pins the environment without it.');
        }

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testTheTypePickerDoesNotOfferIt(): void
    {
        $types = Injector::inst()->get(GridNodeMapper::class)->allowedTypesByContainerType();

        self::assertArrayNotHasKey(UserFormElement::class, $types[ContainerType::Column->value]);
    }

    public function testTheCreateEndpointRefusesIt(): void
    {
        self::assertFalse(ContainerType::Column->isChildCreatable(UserFormElement::class));
    }

    public function testNoMemberMayCreateIt(): void
    {
        self::assertFalse(UserFormElement::singleton()->canCreate($this->createMemberWithPermission('ADMIN')));
    }

    public function testTheElementReportDoesNotListIt(): void
    {
        $field = GridElementReport::create()->parameterFields()->dataFieldByName('ClassName');

        self::assertInstanceOf(DropdownField::class, $field);
        self::assertArrayNotHasKey(UserFormElement::class, $field->getSource());
    }

    /** A record left behind after userforms is uninstalled. */
    public function testAStrayRecordRendersNothing(): void
    {
        $column = GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section(
            $this->objFromFixture(Page::class, 'test_page'),
        )));
        $element = UserFormElement::create();
        $element->Title = 'Contact';
        $element->ShowTitle = true;
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        self::assertSame('', $element->forTemplate());
        self::assertStringNotContainsString('Contact', $column->forTemplate());
    }
}

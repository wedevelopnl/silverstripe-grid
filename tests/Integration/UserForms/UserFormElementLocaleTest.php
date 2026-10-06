<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\UserForms;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use TractorCow\Fluent\Service\CopyToLocaleService;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Tests\Integration\Fluent\FluentGridTestCase;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\UserForms\UserFormElement;

/** Copy-to-locale gives each locale its own form, through the trait's cascade config alone. */
#[CoversNothing]
final class UserFormElementLocaleTest extends FluentGridTestCase
{
    public function testCopyToLocaleGivesTheLocaleItsOwnFields(): void
    {
        $page = $this->createPage();
        $column = GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($page)));
        $form = UserFormElement::create();
        $form->Title = 'Contact';
        $form->ParentID = $column->ID;
        $form->ParentClass = Column::class;
        $form->write();
        $field = EditableTextField::create();
        $field->Title = 'Name';
        $form->Fields()->add($field);

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        $nlForm = UserFormElement::get()->exclude('ID', $form->ID)->first();
        self::assertInstanceOf(UserFormElement::class, $nlForm);
        self::assertSame(['Name'], $nlForm->Fields()->filter('ClassName', EditableTextField::class)->column('Title'));
        self::assertNotContains(
            (int) $field->ID,
            array_map('intval', $nlForm->Fields()->column('ID')),
            'The locale copy must own new field records, not share the source ones',
        );
    }
}

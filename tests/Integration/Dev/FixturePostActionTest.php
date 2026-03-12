<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\FixturePostAction;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Integration tests for FixturePostAction::apply() with real database records.
 *
 * Unit tests cover validation, mocking, and fromConfig(); these tests verify
 * that apply() produces the correct versioned state in the database.
 */
#[CoversClass(FixturePostAction::class)]
final class FixturePostActionTest extends SapphireTest
{
    protected $usesDatabase = true;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testPublishRecursivePublishesRecordToLive(): void
    {
        $element = $this->createContentElement('Publish Me');

        $action = new FixturePostAction(
            action: 'publish_recursive',
            class: ContentElement::class,
            identifier: 'test',
        );

        $action->apply($element);

        Versioned::withVersionedMode(static function () use ($element): void {
            Versioned::set_stage(Versioned::LIVE);
            $live = GridElement::get()->byID($element->ID);
            self::assertNotNull($live, 'Record should exist on live after publish_recursive');
            self::assertSame('Publish Me', $live->Title);
        });
    }

    public function testUnpublishRemovesRecordFromLive(): void
    {
        $element = $this->createContentElement('Unpublish Me');
        $element->publishRecursive();

        // Confirm it's on live before unpublish
        Versioned::withVersionedMode(static function () use ($element): void {
            Versioned::set_stage(Versioned::LIVE);
            self::assertNotNull(GridElement::get()->byID($element->ID));
        });

        $action = new FixturePostAction(
            action: 'unpublish',
            class: ContentElement::class,
            identifier: 'test',
        );

        $action->apply($element);

        Versioned::withVersionedMode(static function () use ($element): void {
            Versioned::set_stage(Versioned::LIVE);
            self::assertNull(
                GridElement::get()->byID($element->ID),
                'Record should not exist on live after unpublish',
            );

            Versioned::set_stage(Versioned::DRAFT);
            self::assertNotNull(
                GridElement::get()->byID($element->ID),
                'Record should still exist in draft after unpublish',
            );
        });
    }

    public function testModifyUpdatesFieldsOnDraftRecord(): void
    {
        $element = $this->createContentElement('Original Title');
        $element->publishRecursive();

        $action = new FixturePostAction(
            action: 'modify',
            class: ContentElement::class,
            identifier: 'test',
            fields: ['Title' => 'Modified Title'],
        );

        $action->apply($element);

        Versioned::withVersionedMode(static function () use ($element): void {
            Versioned::set_stage(Versioned::DRAFT);
            $draft = GridElement::get()->byID($element->ID);
            self::assertSame('Modified Title', $draft->Title, 'Draft should have modified title');

            Versioned::set_stage(Versioned::LIVE);
            $live = GridElement::get()->byID($element->ID);
            self::assertSame('Original Title', $live->Title, 'Live should retain original title');
        });
    }

    public function testModifyWithMultipleFieldsUpdatesAll(): void
    {
        $element = $this->createContentElement('Before');

        $action = new FixturePostAction(
            action: 'modify',
            class: ContentElement::class,
            identifier: 'test',
            fields: ['Title' => 'After', 'Sort' => 99],
        );

        $action->apply($element);

        $updated = GridElement::get()->byID($element->ID);
        $this->assertSame('After', $updated->Title);
        $this->assertSame(99, (int) $updated->Sort);
    }

    /**
     * Create a ContentElement inside a minimal Section→Row→Column hierarchy.
     *
     * The hierarchy is required because auto-scaffolding guards and hierarchy
     * validation expect proper parent relationships.
     */
    private function createContentElement(string $title): ContentElement
    {
        $section = Section::create();
        $section->Title = 'Test Section';
        $section->write();

        // Auto-scaffolding creates Row→Column; grab them
        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        $element = ContentElement::create();
        $element->Title = $title;
        $element->Sort = 1;
        $element->ParentID = $column->ID;
        $element->ParentClass = Column::class;
        $element->write();

        return $element;
    }
}

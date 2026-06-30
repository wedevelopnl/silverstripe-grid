<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use RuntimeException;
use SilverStripe\Core\Extensible;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\MigrationIdMap;

/**
 * Writes the Section → Row → Column → content hierarchy to the DRAFT stage and
 * records the legacy→new id/sort mapping in a {@see MigrationIdMap}.
 *
 * Extracted verbatim from GridMigrationService; carries the
 * `updateClassNameMapping` and `updateElementFieldMapping` extension hooks.
 */
final class DraftHierarchyWriter
{
    use Extensible;

    public function __construct(private readonly FieldMapper $mapper) {}

    /**
     * Write the full Section → Row → Column → Element hierarchy to DRAFT.
     *
     * @param class-string $pageClassName
     * @param list<MigrationSection> $sections
     */
    public function writeDraftHierarchy(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $sections,
        MigrationIdMap $idMap,
    ): void {
        foreach ($sections as $migrationSection) {
            $section = $this->createSection($migrationSection, $pageId, $pageClassName, $zone);

            foreach ($migrationSection->rows as $migrationRow) {
                $row = $this->createRow($migrationRow, (int) $section->ID);

                foreach ($migrationRow->columns as $migrationColumn) {
                    $column = $this->createColumn($migrationColumn, (int) $row->ID);
                    // Freshly written record carries a valid (positive) ID.
                    /** @var positive-int $columnId */
                    $columnId = (int) $column->ID;

                    $elementSort = 1;
                    foreach ($migrationColumn->elements as $legacyElement) {
                        $newElement = $this->buildContentElement($legacyElement, $columnId, $elementSort);
                        $newElement->write();

                        /** @var positive-int $newElementId */
                        $newElementId = (int) $newElement->ID;
                        $idMap->recordElement($legacyElement->id, $newElementId, $columnId, $elementSort);
                        $elementSort++;
                    }
                }
            }
        }
    }

    /**
     * @param class-string $pageClassName
     */
    public function createSection(MigrationSection $migration, int $pageId, string $pageClassName, string $zone): Section
    {
        $section = Section::create();
        $section->Title = '';
        $section->Zone = $zone;
        $section->ExtraClass = $migration->extraClass;
        $section->Sort = $migration->sort;
        $section->ParentID = $pageId;
        $section->ParentClass = $pageClassName;
        $section->write();

        return $section;
    }

    public function createRow(MigrationRow $migration, int $sectionId): Row
    {
        $row = Row::create();
        $row->Title = $migration->title;
        $row->ExtraClass = $migration->extraClass;
        $row->Sort = $migration->sort;
        $row->ParentID = $sectionId;
        $row->ParentClass = Section::class;
        $row->write();

        return $row;
    }

    public function createColumn(MigrationColumn $migration, int $rowId): Column
    {
        $column = Column::create();
        $column->Title = '';
        $column->Sort = $migration->sort;
        $column->ParentID = $rowId;
        $column->ParentClass = Row::class;
        $column->setGridSettings($migration->gridSettings);
        $column->write();

        return $column;
    }

    /**
     * Build a GridElement from legacy data without writing.
     *
     * Shared by both draft creation (writeDraftHierarchy) and live-only
     * creation (createLiveOnlyHierarchy) to avoid duplicating the field
     * mapping, class name resolution, and extension hook logic.
     */
    public function buildContentElement(LegacyElement $legacyElement, int $columnId, int $sort): GridElement
    {
        $newClassName = $this->mapper->resolveClassName($legacyElement->className);
        $oldClassName = $legacyElement->className;
        $this->extend('updateClassNameMapping', $newClassName, $oldClassName);

        if (!\is_a($newClassName, GridElement::class, true)) {
            throw new RuntimeException(\sprintf(
                'Resolved class "%s" (from legacy "%s") does not extend %s.',
                $newClassName,
                $oldClassName,
                GridElement::class,
            ));
        }

        /** @var GridElement $newElement */
        $newElement = $newClassName::create();

        $newElement->Title = $legacyElement->title;
        $newElement->ShowTitle = $legacyElement->showTitle;
        /** @var 'h1'|'h2'|'h3'|'h4'|'h5'|'h6' $titleTag */
        $titleTag = $legacyElement->titleTag !== '' ? $legacyElement->titleTag : 'h2';
        $newElement->TitleTag = $titleTag;
        $newElement->TitleClass = $legacyElement->titleClass;
        $newElement->Sort = $sort;
        $newElement->ExtraClass = $legacyElement->extraClass;
        $newElement->ParentID = $columnId;
        $newElement->ParentClass = Column::class;

        if ($legacyElement->mediaData !== null && $newElement instanceof ContentElement) {
            $html = $legacyElement->mediaData->fields['HTML'] ?? null;
            if (\is_string($html)) {
                $newElement->HTML = $html;
            }

            foreach ($this->mapper->mapMediaFields($legacyElement->mediaData)->toArray() as $field => $value) {
                $newElement->__set($field, $value);
            }
        }

        $this->extend('updateElementFieldMapping', $newElement, $legacyElement);
        /** @var GridElement $newElement */

        return $newElement;
    }
}

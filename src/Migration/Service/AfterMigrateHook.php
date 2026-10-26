<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\FirstCopyRegistry;

/**
 * Fires `onAfterMigrate` on each new element of one page, right after the
 * element is written to a stage, with a {@see MigratedElementContext}.
 *
 * Invoked through `invokeWithExtensions()`, so a project implements the hook on
 * its own block class or on an Extension of it. It runs inside the page's
 * transaction: a throw rolls the whole page back.
 */
final readonly class AfterMigrateHook
{
    /**
     * @param non-empty-string|null $locale
     * @param list<int> $sharedWithPageIds
     */
    public function __construct(
        private int $pageId,
        private int $legacyAreaId,
        private ?string $locale,
        private array $sharedWithPageIds,
        private FirstCopyRegistry $firstCopies,
    ) {}

    /**
     * @param MigratedElementContext::DRAFT|MigratedElementContext::LIVE $stage
     */
    public function fire(GridElement $element, LegacyElement $legacy, string $stage): void
    {
        $context = new MigratedElementContext(
            $stage,
            $this->pageId,
            $this->legacyAreaId,
            $this->locale,
            $this->sharedWithPageIds,
            $this->firstCopy($legacy->id),
        );
        // invokeWithExtensions() takes its arguments by reference.
        $element->invokeWithExtensions('onAfterMigrate', $legacy, $context);
    }

    private function firstCopy(int $legacyId): ?GridElement
    {
        $firstCopy = $this->firstCopies->firstCopy($legacyId);
        if ($firstCopy === null) {
            return null;
        }

        return Versioned::withVersionedMode(static function () use ($firstCopy): ?GridElement {
            Versioned::set_stage(Versioned::DRAFT);
            $elements = GridElement::get();
            if ($firstCopy['locale'] !== null) {
                // A copy from an earlier locale pass is hidden from this one by
                // Fluent's locale isolation unless the query names its locale.
                $elements = $elements->setDataQueryParam('Fluent.Locale', $firstCopy['locale']);
            }

            return $elements->byID($firstCopy['id']);
        });
    }
}

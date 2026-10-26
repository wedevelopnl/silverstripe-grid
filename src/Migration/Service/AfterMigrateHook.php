<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;
use WeDevelop\Grid\Migration\Model\MigratedFirstCopy;
use WeDevelop\Grid\Model\GridElement;

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
            MigratedFirstCopy::of($legacy->id),
        );
        // invokeWithExtensions() takes its arguments by reference.
        $element->invokeWithExtensions('onAfterMigrate', $legacy, $context);
    }
}

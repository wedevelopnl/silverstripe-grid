<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use RuntimeException;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;
use WeDevelop\Grid\Model\GridElement;

/**
 * Records every `onAfterMigrate` call on the element it is applied to, and can
 * make the hook throw for one page to drive a rollback.
 *
 * @extends Extension<GridElement>
 */
final class TestAfterMigrateRecorder extends Extension implements TestOnly
{
    /** @var list<array{element: int, legacy: int, context: MigratedElementContext}> */
    public static array $calls = [];

    /** Page whose live call throws after it was recorded, once both stages hold its writes; null never throws. */
    public static ?int $failOnPage = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$failOnPage = null;
    }

    public function onAfterMigrate(LegacyElement $legacy, MigratedElementContext $context): void
    {
        self::$calls[] = ['element' => (int) $this->getOwner()->ID, 'legacy' => $legacy->id, 'context' => $context];

        if ($context->pageId === self::$failOnPage && $context->stage === MigratedElementContext::LIVE) {
            throw new RuntimeException('Deliberate onAfterMigrate failure');
        }
    }
}

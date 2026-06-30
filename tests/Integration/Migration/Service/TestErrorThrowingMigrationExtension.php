<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\GridElement;

/**
 * Test extension that throws a non-{@see \Exception} {@see \Throwable} when an
 * element's Title matches "ERROR_ME".
 *
 * Mirrors {@see TestFailingMigrationExtension} but raises a {@see \TypeError}
 * (an {@see \Error}, not an {@see \Exception}) to exercise the migration's
 * rollback safety net for unexpected engine/hook errors — the path that
 * {@see \SilverStripe\ORM\Connect\Database::withTransaction()} does NOT cover,
 * since it only catches \Exception.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\DraftHierarchyWriter>
 */
final class TestErrorThrowingMigrationExtension extends Extension
{
    public function updateElementFieldMapping(GridElement $element, LegacyElement $legacyElement): void
    {
        if ($element->Title === 'ERROR_ME') {
            throw new \TypeError('Deliberate test Error (non-Exception Throwable)');
        }
    }
}

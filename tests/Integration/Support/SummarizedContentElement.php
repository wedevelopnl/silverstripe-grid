<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use Override;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\ContentElement;

/**
 * Test-only content element whose {@see getSummary()} return value is
 * controlled by a plain object property. Used to exercise the
 * {@see GridNodeMapper} summary pipeline without relying on any
 * integrator-supplied content elements.
 */
class SummarizedContentElement extends ContentElement implements TestOnly
{
    private static string $table_name = 'GridTestSummarizedContentElement';

    public ?string $testSummary = null;

    #[Override]
    public function getSummary(): ?string
    {
        return $this->testSummary;
    }
}

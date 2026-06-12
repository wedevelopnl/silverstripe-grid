<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use Override;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\ContentElement;

/**
 * Test-only content element that contributes an extra block-schema field via
 * {@see provideBlockSchema()}. Used to prove {@see \WeDevelop\Grid\Model\GridElement::getBlockSchema()}
 * merges subclass-provided keys into the base schema.
 */
class CustomSchemaContentElement extends ContentElement implements TestOnly
{
    private static string $table_name = 'GridTestCustomSchemaContentElement';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function provideBlockSchema(): array
    {
        return ['custom' => 'x'];
    }
}

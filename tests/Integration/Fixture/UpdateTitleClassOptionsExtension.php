<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fixture;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test extension that adds a custom option via the
 * {@see GridElement::getCMSFields()} `updateTitleClassOptions` hook.
 *
 * @phpstan-type TitleClassOptions array<string, string>
 */
class UpdateTitleClassOptionsExtension extends Extension implements TestOnly
{
    /**
     * @param TitleClassOptions $options
     */
    protected function updateTitleClassOptions(array &$options): void
    {
        $options['test-injected-class'] = 'Injected by extension';
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use LogicException;
use Override;
use Page;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\FieldList;

/**
 * Test-only page whose CMS form cannot be built, standing in for a project page
 * type whose getCMSFields() depends on state a CLI run does not have.
 */
class FieldsFailingTestPage extends Page implements TestOnly
{
    public const string FAILURE = 'This form needs a request.';

    private static string $table_name = 'WeDevelop_Grid_Test_FieldsFailingPage';

    #[Override]
    public function getCMSFields(): FieldList
    {
        throw new LogicException(self::FAILURE);
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fixture;

use SilverStripe\Control\Controller;
use SilverStripe\Dev\TestOnly;

class TestController extends Controller implements TestOnly
{
    private static string $url_segment = 'grid-test';
}

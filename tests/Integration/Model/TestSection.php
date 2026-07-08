<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\Section;

/**
 * Section subclass for proving cross-subclass behavior: sections in a zone
 * form one Sort sequence across ALL Section subclasses, so a project subclass
 * must not scope its sort lookup to its own class.
 */
final class TestSection extends Section implements TestOnly
{
}

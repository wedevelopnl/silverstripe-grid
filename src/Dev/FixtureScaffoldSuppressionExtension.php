<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Dev;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\FixtureBlueprint;
use SilverStripe\Dev\FixtureFactory;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Suppresses container auto-scaffolding while E2E fixtures are written.
 *
 * The grid's E2E fixtures are authored top-down (page → section → row →
 * column → leaf), so every container's children are declared explicitly in
 * the YAML. Without suppression, GridElement::onAfterWrite() would scaffold
 * an extra Row under each Section and an extra Column under each Row,
 * duplicating children the fixture creates itself.
 *
 * Registered on the silverstripe-e2e FixtureLoader via _config/dev.yml and
 * invoked through its onBeforeLoad hook. The suppression is scoped to each
 * record's write by FixtureBlueprint's internal Config::nest()/unnest().
 *
 * @extends Extension<FixtureLoader>
 */
class FixtureScaffoldSuppressionExtension extends Extension
{
    public function onBeforeLoad(string $name, FixtureFactory $factory): void
    {
        foreach ([Section::class, Row::class] as $class) {
            $blueprint = new FixtureBlueprint($class);
            $blueprint->addCallback('beforeCreate', static function () use ($class): void {
                Config::modify()->set($class, 'auto_scaffold', false);
            });
            $factory->define($class, $blueprint);
        }
    }
}

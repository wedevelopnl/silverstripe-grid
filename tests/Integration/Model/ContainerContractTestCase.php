<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Extensions\GridPageExtension;

/**
 * Abstract contract test for ContainerInterface implementations.
 *
 * Extend this in each concrete container's test class and implement
 * {@see createContainer()} to return a configured instance.
 */
abstract class ContainerContractTestCase extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        \Page::class => [GridPageExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // FlushableTestState::setUp() calls Versioned::reset() which clears
        // the reading mode to ''. This runs before VersionedTestState::setUp()
        // which only saves (doesn't set) the current mode. Explicitly set
        // draft stage so scaffolding hooks work.
        Versioned::set_stage(Versioned::DRAFT);
    }

    abstract protected function createContainer(): ContainerInterface;

    abstract protected function expectedIcon(): string;

    abstract protected function expectedPluralName(): string;

    abstract protected function expectedClassDescription(): string;

    /**
     * @return class-string<ContainerInterface>
     */
    abstract protected function containerClass(): string;

    public function testIconConfig(): void
    {
        $this->assertSame($this->expectedIcon(), $this->containerClass()::config()->get('icon'));
    }

    public function testPluralNameConfig(): void
    {
        $this->assertSame($this->expectedPluralName(), $this->containerClass()::config()->get('plural_name'));
    }

    public function testClassDescriptionConfig(): void
    {
        $this->assertSame(
            $this->expectedClassDescription(),
            $this->containerClass()::config()->get('class_description'),
        );
    }

    public function testImplementsContainerInterface(): void
    {
        $container = $this->createContainer();

        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    public function testGetContainerTypeReturnsValidCase(): void
    {
        $container = $this->createContainer();
        $type = $container->getContainerType();

        $this->assertContains($type, ContainerType::cases());
    }
}

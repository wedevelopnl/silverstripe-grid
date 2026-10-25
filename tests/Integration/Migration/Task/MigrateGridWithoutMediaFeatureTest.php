<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestClassNameMappingExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestFailingMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Migration\Support\SeedsPublishedLegacyContent;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestMediaContentElement;
use WeDevelop\Grid\Tests\Integration\Support\RecordingLogger;

/**
 * A site that never applied BlockMediaExtension to ContentElement migrates
 * without failing a page: the HTML carries over on both stages, and media the
 * site has no column for is reported once at the end of the run.
 */
#[CoversClass(MigrateGridTask::class)]
final class MigrateGridWithoutMediaFeatureTest extends MigrationTestCase
{
    use SeedsPublishedLegacyContent;

    protected static $illegal_extensions = [
        ContentElement::class => [BlockMediaExtension::class],
    ];

    protected static $required_extensions = [
        TestMediaContentElement::class => [BlockMediaExtension::class],
    ];

    protected static $extra_dataobjects = [TestMediaContentElement::class];

    /**
     * The values ElementContentExtension's `$defaults` wrote onto every legacy
     * block, media or not.
     */
    private const array LEGACY_TEXT_ONLY = [
        'ContentVerticalAlign' => 'align-items-center',
        'MediaPosition' => 'order-1',
    ];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        Injector::inst()->registerService($this->logger, LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        DraftHierarchyWriter::remove_extension(TestClassNameMappingExtension::class);
        DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        TestClassNameMappingExtension::$targetClass = '';

        parent::tearDown();
    }

    public function testMigratesPublishedTextBlocksWithoutFailingAPage(): void
    {
        $this->seedPublishedPage('test_page', 100);
        $this->seedPublishedBlock(100, 1001, 1, '<p>First</p>');
        $this->seedPublishedBlock(100, 1002, 2, '<p>Second</p>');

        $result = $this->migrate();

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertStringContainsString('Migrated 1 page(s) and 2 content block(s); 0 page(s) skipped as already migrated, 0 failed.', $result['output']);
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            self::assertSame(['<p>First</p>', '<p>Second</p>'], $this->blockHtml($stage), "HTML on {$stage}");
        }
    }

    public function testCarriesMediaOverToTheSitesOwnTextBlockTypeThatHoldsTheMediaFields(): void
    {
        TestClassNameMappingExtension::$targetClass = TestMediaContentElement::class;
        DraftHierarchyWriter::add_extension(TestClassNameMappingExtension::class);
        $this->seedPublishedPage('test_page', 100);
        // The image was replaced on draft after the last publish, so live must
        // get its own image rather than the draft copy writeToStage() makes.
        $images = [Versioned::DRAFT => 8, Versioned::LIVE => 7];
        foreach (['draft' => $images[Versioned::DRAFT], 'live' => $images[Versioned::LIVE]] as $legacyStage => $imageId) {
            $this->seeder->seedElement(1001, 100, self::CONTENT_CLASS, 1, ['Title' => 'Block 1001'], $legacyStage);
            $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Text</p>', 'MediaType' => 'image', 'MediaImageID' => $imageId], $legacyStage);
        }

        $result = $this->migrate();

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertStringContainsString('0 failed.', $result['output']);
        foreach ($images as $stage => $imageId) {
            $blocks = $this->migratedBlocks($stage);
            self::assertCount(1, $blocks, "one block on {$stage}");
            self::assertInstanceOf(TestMediaContentElement::class, $blocks[0], "block class on {$stage}");
            self::assertSame(
                ['<p>Text</p>', 'image', $imageId],
                [(string) $blocks[0]->HTML, $blocks[0]->getField('MediaType'), (int) $blocks[0]->getField('MediaImageID')],
                "HTML and image on {$stage}",
            );
        }
    }

    public function testEndsWithOneWarningNamingTheMediaSettingsLeftBehind(): void
    {
        $this->seedPublishedPage('test_page', 100);
        $this->seedPublishedBlock(100, 1001, 1, '<p>Image block</p>', ['MediaType' => 'image', 'MediaImageID' => 7, ...self::LEGACY_TEXT_ONLY]);
        $this->seedPublishedBlock(100, 1002, 2, '<p>Video block</p>', ['MediaType' => 'video', 'MediaVideoFullURL' => 'https://example.com/watch?v=1', ...self::LEGACY_TEXT_ONLY]);
        $this->seedPublishedPage('test_page_2', 200);
        $this->seedPublishedBlock(200, 2001, 1, '<p>Second video</p>', ['MediaType' => 'video', 'MediaVideoFullURL' => 'https://example.com/watch?v=2', ...self::LEGACY_TEXT_ONLY]);

        $result = $this->migrate();

        $warning = '3 text block(s) had media settings that were not carried over: MediaType, MediaImageID, MediaVideoFullURL. '
            . 'No column holds them; the values remain in the legacy ElementContent tables.';
        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertStringEndsWith(
            "\nMigrated 2 page(s) and 3 content block(s); 0 page(s) skipped as already migrated, 0 failed.\n{$warning}\n",
            $result['output'],
        );
        self::assertSame([$warning], $this->logger->messagesAt('warning'));
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            self::assertSame(['<p>Image block</p>', '<p>Video block</p>', '<p>Second video</p>'], $this->blockHtml($stage), "HTML on {$stage}");
        }
    }

    public function testLeavesTheBlocksOfAPageThatRolledBackOutOfTheMediaWarning(): void
    {
        // The FAIL_ME block's media is recorded before the extension throws, so
        // only the rollback can keep it out of the warning.
        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);
        $this->seedPublishedPage('test_page', 100);
        $this->seeder->seedElement(1001, 100, self::CONTENT_CLASS, 1, ['Title' => 'FAIL_ME']);
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Image block</p>', 'MediaType' => 'image', 'MediaImageID' => 7, ...self::LEGACY_TEXT_ONLY]);
        $this->seedPublishedPage('test_page_2', 200);
        $this->seedPublishedBlock(200, 2001, 1, '<p>Video block</p>', ['MediaType' => 'video', 'MediaVideoFullURL' => 'https://example.com/watch?v=1', ...self::LEGACY_TEXT_ONLY]);

        $result = $this->migrate();

        $warning = '1 text block(s) had media settings that were not carried over: MediaType, MediaVideoFullURL. '
            . 'No column holds them; the values remain in the legacy ElementContent tables.';
        self::assertSame(Command::FAILURE, $result['exitCode'], $result['output']);
        self::assertStringEndsWith(
            "\nMigrated 1 page(s) and 1 content block(s); 0 page(s) skipped as already migrated, 1 failed.\n"
            . "Check logs for details.\n{$warning}\n",
            $result['output'],
        );
        self::assertSame([$warning], $this->logger->messagesAt('warning'));
    }

    public function testPrintsNoMediaWarningWhenTheLegacyBlocksHoldOnlyText(): void
    {
        $this->seedPublishedPage('test_page', 100);
        $this->seedPublishedBlock(100, 1001, 1, '<p>First</p>', self::LEGACY_TEXT_ONLY);
        $this->seedPublishedBlock(100, 1002, 2, '<p>Second</p>', self::LEGACY_TEXT_ONLY);

        $result = $this->migrate();

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertStringEndsWith(
            "\nMigrated 1 page(s) and 2 content block(s); 0 page(s) skipped as already migrated, 0 failed.\n",
            $result['output'],
        );
        self::assertSame([], $this->logger->messagesAt('warning'));
    }

    /**
     * @return list<string>
     */
    private function blockHtml(string $stage): array
    {
        return \array_map(
            static fn (ContentElement $element): string => (string) $element->HTML,
            $this->migratedBlocks($stage),
        );
    }
}

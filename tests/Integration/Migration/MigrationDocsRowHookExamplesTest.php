<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Yaml\Yaml;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Service\LivePublisher;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestLiveRowSettingsMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestRowSettingsMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestSlideRecipe;

/**
 * Keeps the row and section hook examples in docs/migration.md (hooks 6–9) and the
 * owned-items recipe (hook 10) the code the migration tests run: each documented
 * method must be the support extension's method, and each registration example must
 * register the example's extension on the class that fires the hook.
 * {@see Task\MigrateGridRowSettingsTest} and {@see OwnedItemsMigrationAcceptanceTest}
 * run that code end to end.
 */
#[CoversNothing]
final class MigrationDocsRowHookExamplesTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const string DOCS = __DIR__ . '/../../../docs/migration.md';

    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function hookExamples(): iterable
    {
        yield 'hook 6' => ['6', TestRowSettingsMigrationExtension::class, 'updateRowFieldMapping'];
        yield 'hook 7' => ['7', TestRowSettingsMigrationExtension::class, 'updateSectionFieldMapping'];
        yield 'hook 8' => ['8', TestLiveRowSettingsMigrationExtension::class, 'updateLiveRowFieldMapping'];
        yield 'hook 9' => ['9', TestLiveRowSettingsMigrationExtension::class, 'updateLiveSectionFieldMapping'];
        yield 'hook 10' => ['10', TestSlideRecipe::class, 'onAfterMigrate'];
    }

    /**
     * @param class-string $supportClass
     */
    #[DataProvider('hookExamples')]
    public function testTheDocumentedHookIsTheCodeTheMigrationTestsRun(string $hook, string $supportClass, string $method): void
    {
        $documented = self::extractMethod(\implode("\n", self::fences($hook, 'php')), $method);
        $supportFile = (string) (new \ReflectionClass($supportClass))->getFileName();
        $tested = self::extractMethod((string) \file_get_contents($supportFile), $method);

        self::assertSame(self::normaliseWhitespace($tested), self::normaliseWhitespace($documented));
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function registrationExamples(): iterable
    {
        yield 'hook 6' => ['6', DraftHierarchyWriter::class];
        yield 'hook 8' => ['8', LivePublisher::class];
    }

    /**
     * @param class-string $firingClass
     */
    #[DataProvider('registrationExamples')]
    public function testTheDocumentedRegistrationAppliesTheExampleExtensionToTheClassThatFiresTheHook(string $hook, string $firingClass): void
    {
        $fences = self::fences($hook, 'yaml');
        self::assertCount(1, $fences);
        /** @var array<string, array{extensions: list<string>}> $config */
        $config = Yaml::parse($fences[0]);
        self::assertSame([$firingClass], \array_keys($config));
        self::assertCount(1, $config[$firingClass]['extensions']);

        // The registered class is the project's own and cannot load here; what ties it to the
        // tested code is that it names the class the hook's PHP example declares.
        self::assertSame(1, \preg_match('/^class (\w+) extends Extension$/m', \implode("\n", self::fences($hook, 'php')), $declared));
        self::assertStringEndsWith('\\' . $declared[1], $config[$firingClass]['extensions'][0]);
    }

    /**
     * @return list<string> the bodies of the given language's code fences in the doc section of one hook
     */
    private static function fences(string $hook, string $language): array
    {
        $docs = (string) \file_get_contents(self::DOCS);
        self::assertSame(1, \preg_match("/\n### {$hook}\\. .*?(?=\n##)/s", $docs, $section), "docs section of hook {$hook}");
        \preg_match_all("/```{$language}\n(.*?)```/s", $section[0], $matches);

        return $matches[1];
    }

    private static function extractMethod(string $source, string $method): string
    {
        $start = \strpos($source, "public function {$method}(");
        self::assertNotFalse($start, "{$method}() not found");
        $position = (int) \strpos($source, '{', $start);
        $depth = 0;
        do {
            $depth += match ($source[$position]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
            ++$position;
        } while ($depth > 0);

        return \substr($source, $start, $position - $start);
    }

    private static function normaliseWhitespace(string $code): string
    {
        return (string) \preg_replace('/\s+/', ' ', \trim($code));
    }
}

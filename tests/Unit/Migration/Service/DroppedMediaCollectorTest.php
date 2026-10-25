<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\MappedMediaFields;
use WeDevelop\Grid\Migration\Service\DroppedMediaCollector;
use WeDevelop\Grid\Migration\Service\FieldMapper;

#[CoversClass(DroppedMediaCollector::class)]
final class DroppedMediaCollectorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nothingChosenProvider(): iterable
    {
        yield 'null' => [['MediaType' => null]];
        yield 'empty string' => [['MediaCaption' => '']];
        yield 'zero ID' => [['MediaImageID' => 0]];
        yield 'zero ID as read from the database' => [['MediaImageID' => '0']];
        yield 'unticked overlay' => [['MediaVideoHasOverlay' => 0]];
        yield 'default vertical alignment' => [['ContentVerticalAlign' => 'align-items-center']];
        yield 'default media position' => [['MediaPosition' => 'order-1']];
        yield 'column absent from the legacy row' => [[]];
        yield 'HTML is not a media setting' => [['HTML' => '<p>Text</p>']];
    }

    /**
     * @param array<string, mixed> $legacyFields
     */
    #[DataProvider('nothingChosenProvider')]
    public function testIgnoresALegacyValueThatChoseNothing(array $legacyFields): void
    {
        $collector = new DroppedMediaCollector();

        $collector->record(1, new LegacyMediaData($legacyFields), [...self::allMediaFields(), 'HTML']);
        $collector->commit();

        self::assertSame(0, $collector->blockCount());
    }

    public function testReportsOnlyTheChosenSettingsWhoseFieldWasDropped(): void
    {
        $collector = new DroppedMediaCollector();

        $collector->record(1, new LegacyMediaData([
            'MediaType' => 'image',
            'MediaImageID' => '7',
            'MediaCaption' => 'Kept caption',
        ]), ['MediaType', 'MediaImageID']);
        $collector->commit();

        self::assertSame([1, ['MediaType', 'MediaImageID']], [$collector->blockCount(), $collector->columns()]);
    }

    public function testCountsABlockRecordedOnSeveralStagesOrPassesOnce(): void
    {
        $collector = new DroppedMediaCollector();

        $collector->record(1, new LegacyMediaData(['MediaVideoFullURL' => 'https://example.com/v']), self::allMediaFields());
        $collector->commit();
        $collector->record(1, new LegacyMediaData(['MediaType' => 'video']), self::allMediaFields());
        $collector->record(2, new LegacyMediaData(['MediaType' => 'image']), self::allMediaFields());
        $collector->commit();

        self::assertSame(
            [2, ['MediaType', 'MediaVideoFullURL']],
            [$collector->blockCount(), $collector->columns()],
            'one count per legacy element; columns deduplicated in legacy schema order',
        );
    }

    public function testForgetsWhatAPageThatRolledBackRecorded(): void
    {
        $collector = new DroppedMediaCollector();

        $collector->record(1, new LegacyMediaData(['MediaType' => 'image']), self::allMediaFields());
        $collector->commit();
        $collector->record(1, new LegacyMediaData(['MediaCaption' => 'Rolled back']), self::allMediaFields());
        $collector->record(2, new LegacyMediaData(['MediaType' => 'video']), self::allMediaFields());
        $collector->rollback();
        $collector->commit();

        self::assertSame([1, ['MediaType']], [$collector->blockCount(), $collector->columns()]);
    }

    public function testKnowsTheLegacyColumnOfEveryMappedMediaField(): void
    {
        self::assertSame(
            \array_keys((new FieldMapper())->mapMediaFields(new LegacyMediaData([]))->toArray()),
            self::allMediaFields(),
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private static function allMediaFields(): array
    {
        return \array_keys(MappedMediaFields::LEGACY_COLUMNS);
    }
}

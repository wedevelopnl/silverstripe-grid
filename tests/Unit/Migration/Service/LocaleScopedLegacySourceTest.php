<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\Service\LocaleAwareLegacyReader;
use WeDevelop\Grid\Migration\Service\LocaleScopedLegacySource;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

#[CoversClass(LocaleScopedLegacySource::class)]
final class LocaleScopedLegacySourceTest extends TestCase
{
    public function testGetElementsForAreaForwardsLocaleContextToTheReader(): void
    {
        $reader = $this->recordingReader();
        $source = new LocaleScopedLegacySource(
            $reader,
            LegacyLocalisationModel::Isolated,
            'nl_NL',
            42,
        );

        $source->getElementsForArea(100, 'live');

        self::assertSame(
            [
                'areaId' => 100,
                'stage' => 'live',
                'model' => LegacyLocalisationModel::Isolated,
                'localeCode' => 'nl_NL',
                'localeId' => 42,
            ],
            $reader->lastLocaleCall,
        );
    }

    public function testGetEligiblePagesDelegatesWithoutLocaleScoping(): void
    {
        $reader = $this->recordingReader();
        $source = new LocaleScopedLegacySource($reader, LegacyLocalisationModel::None, 'en_US', 1);

        $source->getEligiblePages('draft', [7, 8]);

        self::assertSame(['stage' => 'draft', 'pageIds' => [7, 8]], $reader->lastEligibleCall);
    }

    public function testGetPagesWithGridDisabledDelegates(): void
    {
        $reader = $this->recordingReader();
        $source = new LocaleScopedLegacySource($reader, LegacyLocalisationModel::None, 'en_US', 1);

        $source->getPagesWithGridDisabled('live');

        self::assertSame('live', $reader->lastDisabledStage);
    }

    /**
     * @return LocaleAwareLegacyReader&object{lastLocaleCall: ?array<string, mixed>, lastEligibleCall: ?array<string, mixed>, lastDisabledStage: ?string}
     */
    private function recordingReader(): LocaleAwareLegacyReader
    {
        return new class implements LocaleAwareLegacyReader {
            /** @var ?array<string, mixed> */
            public ?array $lastLocaleCall = null;
            /** @var ?array<string, mixed> */
            public ?array $lastEligibleCall = null;
            public ?string $lastDisabledStage = null;

            public function getEligiblePages(string $stage, ?array $pageIds = null): array
            {
                $this->lastEligibleCall = ['stage' => $stage, 'pageIds' => $pageIds];
                return [];
            }

            public function getElementsForArea(int $areaId, string $stage): array
            {
                return [];
            }

            public function getPagesWithGridDisabled(string $stage): array
            {
                $this->lastDisabledStage = $stage;
                return [];
            }

            public function getElementsForAreaInLocale(
                int $areaId,
                string $stage,
                LegacyLocalisationModel $model,
                string $localeCode,
                int $localeId,
            ): array {
                $this->lastLocaleCall = [
                    'areaId' => $areaId,
                    'stage' => $stage,
                    'model' => $model,
                    'localeCode' => $localeCode,
                    'localeId' => $localeId,
                ];
                return [];
            }
        };
    }
}

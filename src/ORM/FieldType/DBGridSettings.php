<?php

declare(strict_types=1);

namespace WeDevelop\Grid\ORM\FieldType;

use Override;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\FieldValidation\CompositeFieldValidator;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\FieldType\DBComposite;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Validation\GridSettingsFieldValidator;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Composite DB field that stores GridSettings as typed sub-columns.
 *
 * Default viewport config (width, offset, visible) is stored as 3 typed
 * columns. Per-viewport overrides are stored as JSON in a Text column,
 * only parsed when non-empty.
 *
 * Physical columns (prefixed with the field name):
 * - {Name}DefaultWidth    INT         — default viewport width
 * - {Name}DefaultOffset   INT         — default viewport offset
 * - {Name}DefaultVisible  BOOLEAN     — default viewport visibility
 * - {Name}Overrides       MEDIUMTEXT  — JSON overrides (null when none)
 */
final class DBGridSettings extends DBComposite
{
    /** @var array<string, array<string, string|null>|null> */
    private static array $field_validators = [
        CompositeFieldValidator::class => null,
        GridSettingsFieldValidator::class => ['columnCount' => 'getColumnCount'],
    ];

    /** @var array<string, string> */
    private static array $composite_db = [
        'DefaultWidth' => 'Int',
        'DefaultOffset' => 'Int',
        'DefaultVisible' => 'Boolean',
        'Overrides' => 'Text',
    ];

    /**
     * Reconstruct the GridSettings VO from sub-fields.
     *
     * "No data" is signalled by `DefaultWidth === null` alone — the same
     * presence test {@see exists()} uses, so the two never disagree. A stored
     * width of 0 is a legitimately-persisted value and is NOT treated as
     * missing: discarding it here would silently drop the whole VO (including
     * overrides) and contradict `exists()`. Rejecting an out-of-range width
     * (such as 0) is {@see GridSettingsFieldValidator}'s job at write time, not
     * a read-boundary concern.
     *
     * A structurally-malformed `Overrides` column degrades to empty overrides
     * (the default still applies to every viewport) and logs a warning, rather
     * than throwing — symmetric with {@see setValue()}, so a single corrupt
     * legacy/fixture row cannot crash an otherwise-valid page render.
     */
    #[Override]
    public function getValue(): ?GridSettings
    {
        /** @var int|null $width */
        $width = $this->getField('DefaultWidth');

        if ($width === null) {
            return null;
        }

        /** @var int $offset */
        $offset = $this->getField('DefaultOffset') ?? 0;
        $visible = (bool) ($this->getField('DefaultVisible') ?? true);

        $default = new ViewportConfig($width, $offset, $visible);

        try {
            $overrides = $this->decodeOverridesColumn($this->getField('Overrides'));
        } catch (InvalidGridValueException $e) {
            $this->logDiscardedValue(
                sprintf('DBGridSettings discarded malformed overrides column: %s', $e->getMessage()),
            );
            $overrides = [];
        }

        return new GridSettings($default, $overrides);
    }

    /**
     * Decode the `{Name}Overrides` Text sub-column back into typed viewport configs.
     *
     * NULL and non-JSON content return an empty map. Structurally malformed
     * stored JSON — e.g. an override entry missing `width` — throws
     * {@see InvalidGridValueException} via {@see ViewportConfig::mapFromArray}.
     * The catch-and-log lives in {@see getValue()} (the field-type boundary),
     * not inside this decoder, so the decoder stays a pure parse and callers
     * that want the strict failure can use it directly.
     *
     * @return array<non-empty-string, ViewportConfig>
     */
    private function decodeOverridesColumn(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return ViewportConfig::mapFromArray($decoded, 'overrides');
    }

    /**
     * Accept a GridSettings VO, JSON string, array, or another DBComposite and decompose into sub-fields.
     *
     * JSON string input is supported for backward compatibility with YAML fixture loading,
     * where SilverStripe's fixture factory passes field values as raw strings.
     *
     * @param GridSettings|string|array<string, mixed>|DBComposite|null $value
     * @param array<string, mixed>|ModelData|null $record
     */
    #[Override]
    public function setValue(mixed $value, null|array|ModelData $record = null, bool $markChanged = true): static
    {
        if ($value instanceof GridSettings) {
            return $this->applyGridSettings($value, $record, $markChanged);
        }

        if (is_string($value)) {
            // Structurally malformed payloads raise InvalidGridValueException
            // from GridSettings::fromJson. At this boundary (ORM field coercion,
            // often hit by fixture/legacy DB data) we preserve the historical
            // "fall through to parent" behaviour so a bad row does not crash
            // unrelated page reads — the domain error is still thrown by
            // direct callers of GridSettings::fromJson.
            try {
                $parsed = GridSettings::fromJson($value);
            } catch (InvalidGridValueException $e) {
                $this->logDiscardedValue(
                    sprintf('DBGridSettings discarded malformed JSON value: %s', $e->getMessage()),
                );
                $parsed = null;
            }

            if ($parsed instanceof GridSettings) {
                return $this->applyGridSettings($parsed, $record, $markChanged);
            }

            // Non-parseable strings (empty, invalid JSON) fall through to parent
            return parent::setValue(null, $record, $markChanged);
        }

        return parent::setValue($value, $record, $markChanged);
    }

    /**
     * Decompose a GridSettings VO into composite sub-fields.
     *
     * @param array<string, mixed>|ModelData|null $record
     */
    private function applyGridSettings(GridSettings $value, null|array|ModelData $record, bool $markChanged): static
    {
        // Bind first if we have a parent record
        if ($record instanceof ModelData) {
            parent::setValue(null, $record, $markChanged);
        }

        $this->setField('DefaultWidth', $value->default->width);
        $this->setField('DefaultOffset', $value->default->offset);
        $this->setField('DefaultVisible', $value->default->visible);
        // Empty map → NULL column (not "{}") so `exists()` semantics match the storage contract.
        // ViewportConfig is JsonSerializable, so json_encode walks the map without a manual loop.
        $this->setField(
            'Overrides',
            $value->overrides === [] ? null : json_encode($value->overrides, JSON_THROW_ON_ERROR),
        );

        return $this;
    }

    /**
     * Record an observable warning when a malformed value is discarded at this
     * field-type boundary (write-side JSON coercion or read-side overrides
     * decode). Logs the reason only — never the payload, which may carry
     * untrusted data — so a bad legacy/fixture row is traceable instead of
     * silently vanishing.
     *
     * @param non-empty-string $message
     */
    private function logDiscardedValue(string $message): void
    {
        Injector::inst()->get(LoggerInterface::class)->warning($message);
    }

    /**
     * GridSettings exists when at least the default width is stored.
     *
     * Overrides the parent which requires ALL sub-fields to exist —
     * Overrides is legitimately empty for most records.
     */
    #[Override]
    public function exists(): bool
    {
        $width = $this->getField('DefaultWidth');

        return $width !== null;
    }

    /**
     * Provide the GridSettings VO for validation instead of sub-field array.
     */
    #[Override]
    public function getValueForValidation(): ?GridSettings
    {
        return $this->getValue();
    }

    /**
     * Column count from the active grid adapter, used by GridSettingsFieldValidator.
     *
     * @return positive-int
     */
    public function getColumnCount(): int
    {
        // A DBComposite field is constructed by the ORM without DI wiring and
        // cannot use $dependencies — resolve the adapter from the container.
        return Injector::inst()->get(GridAdapterInterface::class)->getColumnCount();
    }

    /**
     * Suppress form field scaffolding — GridSettingsField is added explicitly in Column::getCMSFields().
     *
     * @param array<string, mixed> $params
     */
    #[Override]
    public function scaffoldFormField(?string $title = null, array $params = []): ?FormField
    {
        return null;
    }

}

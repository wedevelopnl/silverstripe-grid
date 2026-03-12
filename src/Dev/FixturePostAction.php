<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Dev;

use InvalidArgumentException;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Declarative post-action applied after YAML fixture loading.
 *
 * Encapsulates both the definition and execution of versioned state
 * manipulation (publish, unpublish, modify, attach_image) on fixture records.
 * Used by {@see FixtureLoader} after YAML writing.
 */
final readonly class FixturePostAction
{
    /** @var list<string> */
    private const array VALID_ACTIONS = [
        'publish_recursive',
        'unpublish',
        'modify',
        'attach_image',
    ];

    /** Actions that skip the Versioned extension check. */
    private const array NON_VERSIONED_ACTIONS = [
        'modify',
        'attach_image',
    ];

    /**
     * @param 'publish_recursive'|'unpublish'|'modify'|'attach_image' $action
     * @param class-string $class
     * @param array<string, string|int|float|bool> $fields
     */
    public function __construct(
        public string $action,
        public string $class,
        public string $identifier,
        public array $fields = [],
    ) {
    }

    /**
     * Execute this action against the given record.
     */
    public function apply(DataObject $record): void
    {
        if (
            !in_array($this->action, self::NON_VERSIONED_ACTIONS, true)
            && !$record->hasExtension(Versioned::class)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Post-action "%s" requires Versioned extension, but %s does not have it.',
                $this->action,
                $record::class,
            ));
        }

        /** @var DataObject&Versioned $record */
        match ($this->action) {
            'publish_recursive' => $record->publishRecursive(),
            'unpublish' => $record->doUnpublish(),
            'modify' => $this->applyModify($record),
            'attach_image' => $this->applyAttachImage($record),
        };
    }

    /**
     * @param array{action?: string, class?: class-string, identifier?: string, fields?: array<string, string|int|float|bool>} $config
     */
    public static function fromConfig(array $config): self
    {
        if (
            !is_string($config['action'] ?? null)
            || !is_string($config['class'] ?? null)
            || !is_string($config['identifier'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Post-action config requires "action", "class", and "identifier" keys',
            );
        }

        $action = $config['action'];
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown post-action "%s". Valid actions: %s',
                    $action,
                    implode(', ', self::VALID_ACTIONS),
                ),
            );
        }

        /** @var array<string, string|int|float|bool> $fields */
        $fields = $config['fields'] ?? [];

        return new self(
            action: $action,
            class: $config['class'],
            identifier: $config['identifier'],
            fields: $fields,
        );
    }

    private function applyModify(DataObject $record): void
    {
        foreach ($this->fields as $field => $value) {
            $record->setField($field, $value);
        }

        $record->write();
    }

    /**
     * Create an Image from a local file and attach it to the record via a has_one relation.
     *
     * Requires `fields.relation` (has_one name) and `fields.source` (module resource path).
     * The image is published so it appears on the live site.
     */
    private function applyAttachImage(DataObject $record): void
    {
        /** @var string $relation */
        $relation = $this->fields['relation'] ?? '';
        /** @var string $source */
        $source = $this->fields['source'] ?? '';

        if ($relation === '' || $source === '') {
            throw new InvalidArgumentException(
                'attach_image requires "relation" and "source" in fields',
            );
        }

        $resolved = ModuleResourceLoader::singleton()->resolvePath($source);
        if ($resolved === null) {
            throw new InvalidArgumentException(
                sprintf('Could not resolve image source path: %s', $source),
            );
        }

        $absolutePath = Director::baseFolder() . '/' . $resolved;
        if (!file_exists($absolutePath)) {
            throw new InvalidArgumentException(
                sprintf('Image file not found: %s (resolved from "%s")', $absolutePath, $source),
            );
        }

        $image = Image::create();
        $image->setFromLocalFile($absolutePath, 'Uploads/' . basename($absolutePath));
        $image->write();
        $image->publishSingle();

        $foreignKey = $relation . 'ID';
        $record->setField($foreignKey, $image->ID);
        $record->write();
    }
}

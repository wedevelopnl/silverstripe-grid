<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\ContentElement;
use InvalidArgumentException;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\DuplicateToRequest;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ResetGridSettingsOverridesRequest;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\Viewport;

final readonly class RequestBodyParser
{
    public function __construct(
        private GridAdapterInterface $gridAdapter,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<CreateElementRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseCreateBody(array $data): Result
    {
        $containerTypeValue = $data['containerType'] ?? null;
        $parentData = $data['parent'] ?? null;
        $afterElementID = $data['insertAfterElementID'] ?? null;
        $insertAtStart = $data['insertAtStart'] ?? false;
        $zone = $data['zone'] ?? 'main';

        if (!is_string($containerTypeValue)) {
            return Result::fail(new ValidationError('Invalid or missing containerType.'));
        }

        $containerType = ContainerType::tryFrom($containerTypeValue);
        if ($containerType === null) {
            return Result::fail(new ValidationError('Invalid or missing containerType.'));
        }

        try {
            $parent = NodeRef::fromArray($parentData);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('parent: ' . $e->getMessage()));
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return Result::fail(new ValidationError('insertAfterElementID must be a positive integer or null.'));
        }

        if (!is_bool($insertAtStart)) {
            return Result::fail(new ValidationError('insertAtStart must be a boolean.'));
        }

        if ($insertAtStart && $afterElementID !== null) {
            return Result::fail(new ValidationError('insertAtStart and insertAfterElementID are mutually exclusive.'));
        }

        if (!is_string($zone) || $zone === '') {
            return Result::fail(new ValidationError('zone must be a non-empty string.'));
        }

        /** @var non-empty-string $zone Narrowed by === '' guard above */
        return Result::ok(new CreateElementRequest($parent, $containerType, $zone, $afterElementID, $insertAtStart));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<CreateContentRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseCreateContentBody(array $data): Result
    {
        $className = $data['className'] ?? null;
        $afterElementID = $data['insertAfterElementID'] ?? null;

        if (!is_string($className)) {
            return Result::fail(new ValidationError('className must be a string.'));
        }

        if (!class_exists($className)) {
            return Result::fail(new ValidationError('className does not refer to an existing class.'));
        }

        if ($className !== ContentElement::class && !is_subclass_of($className, ContentElement::class)) {
            return Result::fail(new ValidationError('className must be a ContentElement subclass.'));
        }

        try {
            $parent = NodeRef::fromArray($data['parent'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('parent: ' . $e->getMessage()));
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return Result::fail(new ValidationError('insertAfterElementID must be a positive integer or null.'));
        }

        /** @var class-string<ContentElement> $className */
        return Result::ok(new CreateContentRequest($className, $parent, $afterElementID));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<ReorderRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseReorderBody(array $data): Result
    {
        try {
            $element = NodeRef::fromArray($data['element'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('element: ' . $e->getMessage()));
        }

        if ($element->type === NodeType::Page) {
            return Result::fail(new ValidationError('element type cannot be "page".'));
        }

        try {
            $parent = NodeRef::fromArray($data['parent'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('parent: ' . $e->getMessage()));
        }

        $afterData = $data['after'] ?? null;
        $after = null;
        if ($afterData !== null) {
            try {
                $after = NodeRef::fromArray($afterData);
            } catch (InvalidArgumentException $e) {
                return Result::fail(new ValidationError('after: ' . $e->getMessage()));
            }

            if ($after->type !== $element->type) {
                return Result::fail(new ValidationError('after.type must match element.type.'));
            }
        }

        return Result::ok(new ReorderRequest($element, $parent, $after));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<UpdateGridSettingsRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseUpdateGridSettingsBody(array $data): Result
    {
        $viewport = $data['viewport'] ?? null;
        $width = $data['width'] ?? null;
        $offset = $data['offset'] ?? null;
        $visible = $data['visible'] ?? null;

        try {
            $element = NodeRef::fromArray($data['element'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('element: ' . $e->getMessage()));
        }

        if (!is_string($viewport)) {
            return Result::fail(new ValidationError('viewport must be a string.'));
        }

        if (!$this->isValidViewportKey($viewport)) {
            return Result::fail(new ValidationError('viewport is not a valid viewport key.'));
        }

        /** @var non-empty-string $viewport Validated against adapter viewport keys */

        if (!is_int($width)) {
            return Result::fail(new ValidationError('width must be an integer.'));
        }

        if (!is_int($offset)) {
            return Result::fail(new ValidationError('offset must be an integer.'));
        }

        if (!is_bool($visible)) {
            return Result::fail(new ValidationError('visible must be a boolean.'));
        }

        return Result::ok(new UpdateGridSettingsRequest($element, $viewport, $width, $offset, $visible));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<DuplicateToRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseDuplicateToBody(array $data): Result
    {
        $targetPageId = $data['targetPageId'] ?? null;
        $targetZone = $data['targetZone'] ?? null;

        try {
            $element = NodeRef::fromArray($data['element'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('element: ' . $e->getMessage()));
        }

        if ($element->type === NodeType::Page) {
            return Result::fail(new ValidationError('element type cannot be "page".'));
        }

        if (!is_int($targetPageId) || $targetPageId < 1) {
            return Result::fail(new ValidationError('targetPageId must be a positive integer.'));
        }

        if (!is_string($targetZone) || $targetZone === '') {
            return Result::fail(new ValidationError('targetZone must be a non-empty string.'));
        }

        try {
            $targetParent = NodeRef::fromArray($data['targetParent'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('targetParent: ' . $e->getMessage()));
        }

        /** @var non-empty-string $targetZone Narrowed by === '' guard */
        return Result::ok(new DuplicateToRequest($element, $targetPageId, $targetZone, $targetParent));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<ResetGridSettingsOverridesRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseResetGridSettingsOverridesBody(array $data): Result
    {
        $pageId = $data['pageId'] ?? null;
        $zone = $data['zone'] ?? null;
        $viewport = $data['viewport'] ?? null;

        if (!is_int($pageId) || $pageId < 1) {
            return Result::fail(new ValidationError('pageId must be a positive integer.'));
        }

        if (!is_string($zone) || $zone === '') {
            return Result::fail(new ValidationError('zone must be a non-empty string.'));
        }

        if ($viewport !== null) {
            if (!is_string($viewport) || $viewport === '') {
                return Result::fail(new ValidationError('viewport must be a non-empty string or null.'));
            }

            if (!$this->isValidViewportKey($viewport)) {
                return Result::fail(new ValidationError('viewport is not a valid viewport key.'));
            }

            if ($viewport === $this->gridAdapter->getDefaultViewport()->key) {
                return Result::fail(new ValidationError('Cannot reset the default viewport — it has no overrides.'));
            }
        }

        /** @var non-empty-string $zone Narrowed by === '' guard above */
        return Result::ok(new ResetGridSettingsOverridesRequest($pageId, $zone, $viewport));
    }

    /**
     * Parse a NodeRef-shaped element identifier from a decoded JSON body
     * keyed under `element`. Used by the single-target mutation endpoints
     * (publish, unpublish, archive, duplicate) so every mutation request
     * carries the same `{type, id}` envelope.
     *
     * @param array<string, mixed> $data
     * @return Result<NodeRef>
     */
    #[NoDiscard('The Result carries the parsed element ref or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseElementRef(array $data): Result
    {
        try {
            $element = NodeRef::fromArray($data['element'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError('element: ' . $e->getMessage()));
        }

        if ($element->type === NodeType::Page) {
            return Result::fail(new ValidationError('element type cannot be "page".'));
        }

        return Result::ok($element);
    }

    /**
     * Viewport keys the active adapter recognises.
     *
     * @return list<non-empty-string>
     */
    private function validViewportKeys(): array
    {
        return array_map(
            static fn (Viewport $vp): string => $vp->key,
            $this->gridAdapter->getViewports(),
        );
    }

    private function isValidViewportKey(string $key): bool
    {
        return in_array($key, $this->validViewportKeys(), true);
    }
}

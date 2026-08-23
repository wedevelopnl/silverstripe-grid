<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use InvalidArgumentException;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\ConvertToSharedBlockRequest;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\DuplicateToRequest;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\PlaceSharedBlockRequest;
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ResetGridSettingsOverridesRequest;
use WeDevelop\Grid\Value\SetSharedBlockPublishedRequest;
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

        $parentResult = $this->parseNodeRef($parentData, 'parent');
        if ($parentResult->isErr()) {
            return Result::fail(...$parentResult->errors());
        }
        $parent = $parentResult->unwrap();

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

        $classResult = $this->parseLeafElementClass($className);
        if ($classResult->isErr()) {
            return Result::fail(...$classResult->errors());
        }
        $className = $classResult->unwrap();

        $parentResult = $this->parseNodeRef($data['parent'] ?? null, 'parent');
        if ($parentResult->isErr()) {
            return Result::fail(...$parentResult->errors());
        }
        $parent = $parentResult->unwrap();

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return Result::fail(new ValidationError('insertAfterElementID must be a positive integer or null.'));
        }

        return Result::ok(new CreateContentRequest($className, $parent, $afterElementID));
    }

    /**
     * A concrete element class a Column can hold — the rule shared by content
     * elements created inside a column and leaf-rooted library blocks.
     *
     * @return Result<class-string<GridElement>>
     */
    #[NoDiscard('The Result carries the validated class name or the reason it was rejected.')]
    private function parseLeafElementClass(mixed $className): Result
    {
        if (!is_string($className)) {
            return Result::fail(new ValidationError('className must be a string.'));
        }

        if (!class_exists($className)) {
            return Result::fail(new ValidationError('className does not refer to an existing class.'));
        }

        if (!ContainerType::Column->isChildCreatable($className)) {
            return Result::fail(new ValidationError('className is not an element type a column can hold.'));
        }

        // A reference carries the block it stands for, which these bodies have
        // no slot for. Placements go through the dedicated shared-block
        // endpoints, and a reference may never root a block at all.
        if (is_a($className, SharedBlockReference::class, true)) {
            return Result::fail(new ValidationError('Shared blocks are placed through their own endpoint.'));
        }

        /** @var class-string<GridElement> $className Narrowed by isChildCreatable() above */
        return Result::ok($className);
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<PlaceSharedBlockRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parsePlaceSharedBlockBody(array $data): Result
    {
        $blockId = $data['blockId'] ?? null;
        $zone = $data['zone'] ?? '';
        $afterElementID = $data['insertAfterElementID'] ?? null;
        $insertAtStart = $data['insertAtStart'] ?? false;

        if (!is_int($blockId) || $blockId < 1) {
            return Result::fail(new ValidationError('blockId must be a positive integer.'));
        }

        $parentResult = $this->parseNodeRef($data['parent'] ?? null, 'parent');
        if ($parentResult->isErr()) {
            return Result::fail(...$parentResult->errors());
        }

        if (!is_string($zone)) {
            return Result::fail(new ValidationError('zone must be a string.'));
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

        return Result::ok(new PlaceSharedBlockRequest($blockId, $parentResult->unwrap(), $zone, $afterElementID, $insertAtStart));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<ConvertToSharedBlockRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseConvertToSharedBlockBody(array $data): Result
    {
        $title = $data['title'] ?? '';

        $elementResult = $this->parseElementRef($data);
        if ($elementResult->isErr()) {
            return Result::fail(...$elementResult->errors());
        }

        if (!is_string($title)) {
            return Result::fail(new ValidationError('title must be a string.'));
        }

        return Result::ok(new ConvertToSharedBlockRequest($elementResult->unwrap(), $title));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<SetSharedBlockPublishedRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseSetSharedBlockPublishedBody(array $data): Result
    {
        $blockId = $data['blockId'] ?? null;
        $published = $data['published'] ?? null;

        if (!is_int($blockId) || $blockId < 1) {
            return Result::fail(new ValidationError('blockId must be a positive integer.'));
        }

        if (!is_bool($published)) {
            return Result::fail(new ValidationError('published must be a boolean.'));
        }

        return Result::ok(new SetSharedBlockPublishedRequest($blockId, $published));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<ReorderRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseReorderBody(array $data): Result
    {
        $elementResult = $this->parseElementRef($data);
        if ($elementResult->isErr()) {
            return Result::fail(...$elementResult->errors());
        }
        $element = $elementResult->unwrap();

        $parentResult = $this->parseNodeRef($data['parent'] ?? null, 'parent');
        if ($parentResult->isErr()) {
            return Result::fail(...$parentResult->errors());
        }
        $parent = $parentResult->unwrap();

        $afterData = $data['after'] ?? null;
        $after = null;
        if ($afterData !== null) {
            $afterResult = $this->parseNodeRef($afterData, 'after');
            if ($afterResult->isErr()) {
                return Result::fail(...$afterResult->errors());
            }
            $after = $afterResult->unwrap();

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

        $elementResult = $this->parseNodeRef($data['element'] ?? null, 'element');
        if ($elementResult->isErr()) {
            return Result::fail(...$elementResult->errors());
        }
        $element = $elementResult->unwrap();

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

        if ($width < 1) {
            return Result::fail(new ValidationError('width must be at least 1.'));
        }

        if ($offset < 0) {
            return Result::fail(new ValidationError('offset must not be negative.'));
        }

        /** @var positive-int $width */
        /** @var int<0, max> $offset */

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

        $elementResult = $this->parseElementRef($data);
        if ($elementResult->isErr()) {
            return Result::fail(...$elementResult->errors());
        }
        $element = $elementResult->unwrap();

        if (!is_int($targetPageId) || $targetPageId < 1) {
            return Result::fail(new ValidationError('targetPageId must be a positive integer.'));
        }

        if (!is_string($targetZone) || $targetZone === '') {
            return Result::fail(new ValidationError('targetZone must be a non-empty string.'));
        }

        $targetParentResult = $this->parseNodeRef($data['targetParent'] ?? null, 'targetParent');
        if ($targetParentResult->isErr()) {
            return Result::fail(...$targetParentResult->errors());
        }
        $targetParent = $targetParentResult->unwrap();

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
        $elementResult = $this->parseNodeRef($data['element'] ?? null, 'element');
        if ($elementResult->isErr()) {
            return Result::fail(...$elementResult->errors());
        }
        $element = $elementResult->unwrap();

        if ($element->type === NodeType::Page) {
            return Result::fail(new ValidationError('element type cannot be "page".'));
        }

        return Result::ok($element);
    }

    /**
     * Parse a NodeRef from raw DELETE query-string values (`?type=…&id=…`).
     *
     * DELETE bodies are not universally supported, so single-target deletes send
     * the element identity on the query string. Coerce the raw values into the
     * `{element:{type,id}}` envelope {@see parseElementRef} expects.
     *
     * @return Result<NodeRef>
     */
    #[NoDiscard('The Result carries the parsed element ref or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseElementRefFromQuery(mixed $rawType, mixed $rawId): Result
    {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);

        return $this->parseElementRef([
            'element' => [
                'type' => is_string($rawType) ? $rawType : null,
                'id' => $id === false ? null : $id,
            ],
        ]);
    }

    /**
     * Parse a reset-overrides request from raw query-string values, coercing them
     * to the same shape {@see parseResetGridSettingsOverridesBody} sees from a
     * JSON body (query values are always strings).
     *
     * @return Result<ResetGridSettingsOverridesRequest>
     */
    #[NoDiscard('The Result carries the parsed request or validation errors; discarding it silently drops malformed-input failures.')]
    public function parseResetGridSettingsOverridesFromQuery(mixed $rawPageId, mixed $rawZone, mixed $rawViewport): Result
    {
        $pageId = filter_var($rawPageId, FILTER_VALIDATE_INT);

        return $this->parseResetGridSettingsOverridesBody([
            'pageId' => $pageId === false ? null : $pageId,
            'zone' => is_string($rawZone) ? $rawZone : null,
            'viewport' => is_string($rawViewport) && $rawViewport !== '' ? $rawViewport : null,
        ]);
    }

    /**
     * Bridge {@see NodeRef}'s constructor exception into the Result pattern.
     *
     * NodeRef throws on invalid input, but a malformed request field is an
     * expected failure, not an exceptional one — every endpoint needs the same
     * translation, prefixed with the field it came from.
     *
     * @return Result<NodeRef>
     */
    #[NoDiscard('The Result carries the parsed ref or the validation error; discarding it silently accepts a malformed ref.')]
    private function parseNodeRef(mixed $data, string $field): Result
    {
        try {
            return Result::ok(NodeRef::fromArray($data));
        } catch (InvalidArgumentException $e) {
            return Result::fail(new ValidationError($field . ': ' . $e->getMessage()));
        }
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

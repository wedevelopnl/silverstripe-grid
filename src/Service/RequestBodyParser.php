<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\DuplicateToRequest;
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
    public function parseCreateBody(array $data): Result
    {
        $containerTypeValue = $data['containerType'] ?? null;
        $parentId = $data['parentId'] ?? null;
        $afterElementID = $data['insertAfterElementID'] ?? null;
        $zone = $data['zone'] ?? 'main';

        if (!is_string($containerTypeValue)) {
            return Result::fail(new ValidationError('Invalid or missing containerType.'));
        }

        $containerType = ContainerType::tryFrom($containerTypeValue);
        if ($containerType === null) {
            return Result::fail(new ValidationError('Invalid or missing containerType.'));
        }

        if (!is_int($parentId) || $parentId < 1) {
            return Result::fail(new ValidationError('parentId must be a positive integer.'));
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return Result::fail(new ValidationError('insertAfterElementID must be a positive integer or null.'));
        }

        if (!is_string($zone) || $zone === '') {
            return Result::fail(new ValidationError('zone must be a non-empty string.'));
        }

        /** @var non-empty-string $zone Narrowed by === '' guard above */
        return Result::ok(new CreateElementRequest($containerType, $parentId, $afterElementID, $zone));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<CreateContentRequest>
     */
    public function parseCreateContentBody(array $data): Result
    {
        $className = $data['className'] ?? null;
        $parentId = $data['parentId'] ?? null;
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

        if (!is_int($parentId) || $parentId < 1) {
            return Result::fail(new ValidationError('parentId must be a positive integer.'));
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return Result::fail(new ValidationError('insertAfterElementID must be a positive integer or null.'));
        }

        /** @var class-string<ContentElement> $className */
        return Result::ok(new CreateContentRequest($className, $parentId, $afterElementID));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<ReorderRequest>
     */
    public function parseReorderBody(array $data): Result
    {
        $elementID = $data['elementID'] ?? null;
        $targetParentId = $data['targetParentId'] ?? null;
        $afterElementID = $data['afterElementID'] ?? null;

        if (!is_int($elementID) || $elementID < 1) {
            return Result::fail(new ValidationError('elementID must be a positive integer.'));
        }

        if (!is_int($targetParentId) || $targetParentId < 1) {
            return Result::fail(new ValidationError('targetParentId must be a positive integer.'));
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return Result::fail(new ValidationError('afterElementID must be a positive integer or null.'));
        }

        return Result::ok(new ReorderRequest($elementID, $targetParentId, $afterElementID));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<UpdateGridSettingsRequest>
     */
    public function parseUpdateGridSettingsBody(array $data): Result
    {
        $id = $data['id'] ?? null;
        $viewport = $data['viewport'] ?? null;
        $width = $data['width'] ?? null;
        $offset = $data['offset'] ?? null;
        $visible = $data['visible'] ?? null;

        if (!is_int($id) || $id < 1) {
            return Result::fail(new ValidationError('id must be a positive integer.'));
        }

        if (!is_string($viewport)) {
            return Result::fail(new ValidationError('viewport must be a string.'));
        }

        $validKeys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $this->gridAdapter->getViewports(),
        );
        if (!in_array($viewport, $validKeys, true)) {
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

        return Result::ok(new UpdateGridSettingsRequest($id, $viewport, $width, $offset, $visible));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<DuplicateToRequest>
     */
    public function parseDuplicateToBody(array $data): Result
    {
        $id = $data['id'] ?? null;
        $targetPageId = $data['targetPageId'] ?? null;
        $targetZone = $data['targetZone'] ?? null;
        $targetParentId = $data['targetParentId'] ?? null;

        if (!is_int($id) || $id < 1) {
            return Result::fail(new ValidationError('id must be a positive integer.'));
        }

        if (!is_int($targetPageId) || $targetPageId < 1) {
            return Result::fail(new ValidationError('targetPageId must be a positive integer.'));
        }

        if (!is_string($targetZone) || $targetZone === '') {
            return Result::fail(new ValidationError('targetZone must be a non-empty string.'));
        }

        if (!is_int($targetParentId) || $targetParentId < 1) {
            return Result::fail(new ValidationError('targetParentId must be a positive integer.'));
        }

        /** @var non-empty-string $targetZone Narrowed by === '' guard */
        return Result::ok(new DuplicateToRequest($id, $targetPageId, $targetZone, $targetParentId));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<ResetGridSettingsOverridesRequest>
     */
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

            $validKeys = array_map(
                static fn (Viewport $vp): string => $vp->key,
                $this->gridAdapter->getViewports(),
            );
            if (!in_array($viewport, $validKeys, true)) {
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
     * @param array<string, mixed> $data
     * @return Result<positive-int>
     */
    public function parseElementId(array $data): Result
    {
        $id = $data['id'] ?? null;

        if (!is_int($id) || $id < 1) {
            return Result::fail(new ValidationError('id must be a positive integer.'));
        }

        return Result::ok($id);
    }
}

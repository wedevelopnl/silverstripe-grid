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
            return $this->fail('Invalid or missing containerType.');
        }

        $containerType = ContainerType::tryFrom($containerTypeValue);
        if ($containerType === null) {
            return $this->fail('Invalid or missing containerType.');
        }

        if (!is_int($parentId) || $parentId < 1) {
            return $this->fail('parentId must be a positive integer.');
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return $this->fail('insertAfterElementID must be a positive integer or null.');
        }

        if (!is_string($zone) || $zone === '') {
            return $this->fail('zone must be a non-empty string.');
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
            return $this->fail('className must be a string.');
        }

        if (!class_exists($className)) {
            return $this->fail('className does not refer to an existing class.');
        }

        if ($className !== ContentElement::class && !is_subclass_of($className, ContentElement::class)) {
            return $this->fail('className must be a ContentElement subclass.');
        }

        if (!is_int($parentId) || $parentId < 1) {
            return $this->fail('parentId must be a positive integer.');
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return $this->fail('insertAfterElementID must be a positive integer or null.');
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
            return $this->fail('elementID must be a positive integer.');
        }

        if (!is_int($targetParentId) || $targetParentId < 1) {
            return $this->fail('targetParentId must be a positive integer.');
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return $this->fail('afterElementID must be a positive integer or null.');
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
            return $this->fail('id must be a positive integer.');
        }

        if (!is_string($viewport)) {
            return $this->fail('viewport must be a string.');
        }

        $validKeys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $this->gridAdapter->getViewports(),
        );
        if (!in_array($viewport, $validKeys, true)) {
            return $this->fail('viewport is not a valid viewport key.');
        }

        /** @var non-empty-string $viewport Validated against adapter viewport keys */

        if (!is_int($width)) {
            return $this->fail('width must be an integer.');
        }

        if (!is_int($offset)) {
            return $this->fail('offset must be an integer.');
        }

        if (!is_bool($visible)) {
            return $this->fail('visible must be a boolean.');
        }

        /** @var positive-int $width Structural check only — range validated by GridSettingsFieldValidator */
        /** @var non-negative-int $offset Structural check only — range validated by GridSettingsFieldValidator */
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
            return $this->fail('id must be a positive integer.');
        }

        if (!is_int($targetPageId) || $targetPageId < 1) {
            return $this->fail('targetPageId must be a positive integer.');
        }

        if (!is_string($targetZone) || $targetZone === '') {
            return $this->fail('targetZone must be a non-empty string.');
        }

        if (!is_int($targetParentId) || $targetParentId < 1) {
            return $this->fail('targetParentId must be a positive integer.');
        }

        /** @var non-empty-string $targetZone Narrowed by === '' guard */
        return Result::ok(new DuplicateToRequest($id, $targetPageId, $targetZone, $targetParentId));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<positive-int>
     */
    public function parseElementId(array $data): Result
    {
        $id = $data['id'] ?? null;

        if (!is_int($id) || $id < 1) {
            return $this->fail('id must be a positive integer.');
        }

        return Result::ok($id);
    }

    /**
     * @param non-empty-string $message
     * @return Result<never>
     */
    private function fail(string $message): Result
    {
        return Result::fail(new ValidationError($message));
    }
}

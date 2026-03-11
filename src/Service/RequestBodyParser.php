<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\Viewport;

final class RequestBodyParser
{
    public function __construct(
        private readonly GridAdapterInterface $gridAdapter,
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
            return self::fail('Invalid or missing containerType.');
        }

        $containerType = ContainerType::tryFrom($containerTypeValue);
        if ($containerType === null) {
            return self::fail('Invalid or missing containerType.');
        }

        if (!is_int($parentId) || $parentId < 1) {
            return self::fail('parentId must be a positive integer.');
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return self::fail('insertAfterElementID must be a positive integer or null.');
        }

        if (!is_string($zone) || $zone === '') {
            return self::fail('zone must be a non-empty string.');
        }

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
            return self::fail('className must be a string.');
        }

        if (!class_exists($className)) {
            return self::fail('className does not refer to an existing class.');
        }

        if ($className !== ContentElement::class && !is_subclass_of($className, ContentElement::class)) {
            return self::fail('className must be a ContentElement subclass.');
        }

        if (!is_int($parentId) || $parentId < 1) {
            return self::fail('parentId must be a positive integer.');
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return self::fail('insertAfterElementID must be a positive integer or null.');
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
            return self::fail('elementID must be a positive integer.');
        }

        if (!is_int($targetParentId) || $targetParentId < 1) {
            return self::fail('targetParentId must be a positive integer.');
        }

        if ($afterElementID !== null && (!is_int($afterElementID) || $afterElementID < 1)) {
            return self::fail('afterElementID must be a positive integer or null.');
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
            return self::fail('id must be a positive integer.');
        }

        if (!is_string($viewport)) {
            return self::fail('viewport must be a string.');
        }

        $validKeys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $this->gridAdapter->getViewports(),
        );
        if (!in_array($viewport, $validKeys, true)) {
            return self::fail('viewport is not a valid viewport key.');
        }

        $columnCount = $this->gridAdapter->getColumnCount();

        if (!is_int($width) || $width < 1 || $width > $columnCount) {
            return self::fail(sprintf('Width must be between 1 and %d.', $columnCount));
        }

        if (!is_int($offset) || $offset < 0 || $offset > $columnCount - 1) {
            return self::fail(sprintf('Offset must be between 0 and %d.', $columnCount - 1));
        }

        if ($width + $offset > $columnCount) {
            return self::fail(sprintf(
                'Width (%d) plus offset (%d) exceeds the maximum of %d columns.',
                $width,
                $offset,
                $columnCount,
            ));
        }

        if (!is_bool($visible)) {
            return self::fail('visible must be a boolean.');
        }

        return Result::ok(new UpdateGridSettingsRequest($id, $viewport, $width, $offset, $visible));
    }

    /**
     * @param array<string, mixed> $data
     * @return Result<positive-int>
     */
    public function parseElementId(array $data): Result
    {
        $id = $data['id'] ?? null;

        if (!is_int($id) || $id < 1) {
            return self::fail('id must be a positive integer.');
        }

        return Result::ok($id);
    }

    /**
     * @return Result<never>
     */
    private static function fail(string $message): Result
    {
        return Result::fail(new ValidationError($message));
    }
}

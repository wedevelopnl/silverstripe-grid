<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use InvalidArgumentException;
use JsonSerializable;
use Override;

/**
 * Scoped identity for a single node in the grid tree.
 *
 * Combines a NodeType with a positive integer record ID to form a
 * collision-free reference across SilverStripe's polymorphic parent
 * relationship. This is the canonical on-the-wire and in-memory shape
 * for identifying any grid node (page, section, row, column, element).
 *
 * @phpstan-type SerializedNodeRef array{type: string, id: positive-int}
 */
final readonly class NodeRef implements JsonSerializable
{
    /**
     * @param positive-int $id
     */
    public function __construct(
        public NodeType $type,
        public int $id,
    ) {
        if ($id <= 0) { // @phpstan-ignore smallerOrEqual.alwaysFalse (runtime guard: native type is int)
            throw new InvalidArgumentException('NodeRef id must be a positive integer');
        }
    }

    /**
     * Parse a NodeRef from a decoded JSON payload.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('NodeRef payload must be an object');
        }

        if (!array_key_exists('type', $data)) {
            throw new InvalidArgumentException('NodeRef payload missing "type"');
        }

        if (!array_key_exists('id', $data)) {
            throw new InvalidArgumentException('NodeRef payload missing "id"');
        }

        $type = $data['type'];
        $id = $data['id'];

        if (!is_string($type)) {
            throw new InvalidArgumentException('NodeRef "type" must be a string');
        }

        $nodeType = NodeType::tryFrom($type);
        if ($nodeType === null) {
            throw new InvalidArgumentException(sprintf('Unknown NodeType "%s"', $type));
        }

        if (!is_int($id) || $id <= 0) {
            throw new InvalidArgumentException('NodeRef "id" must be a positive integer');
        }

        return new self($nodeType, $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /**
     * String form matching the frontend's NodeKey: "${type}-${id}".
     */
    public function toKey(): string
    {
        return sprintf('%s-%d', $this->type->value, $this->id);
    }

    /**
     * @return SerializedNodeRef
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
        ];
    }
}

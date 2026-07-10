<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

#[CoversClass(NodeRef::class)]
final class NodeRefTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveIdProvider(): iterable
    {
        yield 'zero id' => [0];
        yield 'negative id' => [-1];
    }

    #[DataProvider('nonPositiveIdProvider')]
    public function testConstructorRejectsNonPositiveId(int $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NodeRef id must be a positive integer');

        new NodeRef(NodeType::Section, $id); // @phpstan-ignore argument.type (runtime guard under test)
    }

    public function testJsonSerializeEmitsTypeAndId(): void
    {
        $ref = new NodeRef(NodeType::Row, 17);

        self::assertSame(['type' => 'row', 'id' => 17], $ref->jsonSerialize());
    }

    public function testToKeyMatchesFrontendFormat(): void
    {
        $ref = new NodeRef(NodeType::Page, 1);

        self::assertSame('page-1', $ref->toKey());
    }

    /**
     * @return array<string, array{NodeRef, NodeRef, bool}>
     */
    public static function equalsProvider(): array
    {
        return [
            'identical refs are equal' => [
                new NodeRef(NodeType::Section, 1),
                new NodeRef(NodeType::Section, 1),
                true,
            ],
            'different type is not equal' => [
                new NodeRef(NodeType::Page, 1),
                new NodeRef(NodeType::Section, 1),
                false,
            ],
            'different id is not equal' => [
                new NodeRef(NodeType::Row, 1),
                new NodeRef(NodeType::Row, 2),
                false,
            ],
        ];
    }

    #[DataProvider('equalsProvider')]
    public function testEquals(NodeRef $a, NodeRef $b, bool $expected): void
    {
        self::assertSame($expected, $a->equals($b));
    }

    public function testFromArrayParsesValidPayload(): void
    {
        $ref = NodeRef::fromArray(['type' => 'column', 'id' => 5]);

        self::assertSame(NodeType::Column, $ref->type);
        self::assertSame(5, $ref->id);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function fromArrayRejectsProvider(): array
    {
        return [
            'non-array payload' => ['not-an-array', 'NodeRef payload must be an object'],
            'missing type' => [['id' => 1], 'NodeRef payload missing "type"'],
            'missing id' => [['type' => 'section'], 'NodeRef payload missing "id"'],
            'non-string type' => [['type' => 1, 'id' => 1], 'NodeRef "type" must be a string'],
            'unknown type' => [['type' => 'unknown', 'id' => 1], 'Unknown NodeType "unknown"'],
            'non-int id' => [['type' => 'section', 'id' => '1'], 'NodeRef "id" must be a positive integer'],
            'zero id' => [['type' => 'section', 'id' => 0], 'NodeRef "id" must be a positive integer'],
            'negative id' => [['type' => 'section', 'id' => -1], 'NodeRef "id" must be a positive integer'],
        ];
    }

    #[DataProvider('fromArrayRejectsProvider')]
    public function testFromArrayRejectsInvalidPayload(mixed $payload, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        NodeRef::fromArray($payload);
    }
}

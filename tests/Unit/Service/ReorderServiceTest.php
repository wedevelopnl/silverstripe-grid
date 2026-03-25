<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Service\ReorderService;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;

#[CoversClass(ReorderService::class)]
final class ReorderServiceTest extends TestCase
{
    private ReorderValidatorInterface&MockObject $validator;

    private GridElementRepositoryInterface&MockObject $repository;

    private ReorderService $service;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ReorderValidatorInterface::class);
        $this->validator->method('validate')->willReturnCallback(
            static fn (GridElement $element): Result => Result::ok($element),
        );
        $this->repository = $this->createMock(GridElementRepositoryInterface::class);
        $this->service = new ReorderService($this->validator, $this->repository);
    }

    public function testSameAreaMoveBackward(): void
    {
        // [A(1), B(2), C(3), D(4), E(5)] → move D after A
        // Expected: [A(1), D(2), B(3), C(4), E(5)]
        $area = $this->createParentMock(10);
        [$a, $b, $c, $d, $e] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
            ['id' => 3, 'sort' => 3, 'parentId' => 10],
            ['id' => 4, 'sort' => 4, 'parentId' => 10],
            ['id' => 5, 'sort' => 5, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b, $c, $d, $e]);

        $result = $this->service->reorder($d, $area, $a->ID);

        self::assertTrue($result->isOk());
        self::assertSame($d, $result->unwrap());

        self::assertSame(1, $a->Sort);
        self::assertSame(2, $d->Sort);
        self::assertSame(3, $b->Sort);
        self::assertSame(4, $c->Sort);
        self::assertSame(5, $e->Sort);
    }

    public function testSameAreaMoveForward(): void
    {
        // [A(1), B(2), C(3), D(4), E(5)] → move B after D
        // Expected: [A(1), C(2), D(3), B(4), E(5)]
        $area = $this->createParentMock(10);
        [$a, $b, $c, $d, $e] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
            ['id' => 3, 'sort' => 3, 'parentId' => 10],
            ['id' => 4, 'sort' => 4, 'parentId' => 10],
            ['id' => 5, 'sort' => 5, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b, $c, $d, $e]);

        $result = $this->service->reorder($b, $area, $d->ID);

        self::assertTrue($result->isOk());
        self::assertSame($b, $result->unwrap());

        self::assertSame(1, $a->Sort);
        self::assertSame(2, $c->Sort);
        self::assertSame(3, $d->Sort);
        self::assertSame(4, $b->Sort);
        self::assertSame(5, $e->Sort);
    }

    public function testSamePositionIsNoOp(): void
    {
        // [A(1), B(2), C(3)] → move B after A (already there)
        $area = $this->createParentMock(10);
        [$a, $b, $c] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
            ['id' => 3, 'sort' => 3, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b, $c]);

        $result = $this->service->reorder($b, $area, $a->ID);

        self::assertTrue($result->isOk());
        self::assertSame($b, $result->unwrap());
        self::assertSame(1, $a->Sort);
        self::assertSame(2, $b->Sort);
        self::assertSame(3, $c->Sort);
    }

    public function testCrossAreaMoveUpdatesParentId(): void
    {
        // Source area 10: [A(1), B(2)] → move B to target area 20 at first position
        // Target area 20: [X(1), Y(2)] → [B(1), X(2), Y(3)]
        // Source area 10: [A(1)] → no gaps, A unchanged
        $targetArea = $this->createParentMock(20);

        $a = $this->createElementMock(1, 1, 10);
        $b = $this->createElementMock(2, 2, 10);
        $x = $this->createElementMock(3, 1, 20);
        $y = $this->createElementMock(4, 2, 20);

        $this->repository->method('findByParentIds')->willReturnCallback(
            static fn (array $ids, string $parentClass): array => match ($ids) {
                [20] => [$x, $y],
                [10] => [$a, $b],
                default => [],
            },
        );

        $result = $this->service->reorder($b, $targetArea, null);

        self::assertTrue($result->isOk());
        self::assertSame($b, $result->unwrap());

        self::assertSame(20, $b->ParentID);
        self::assertSame(1, $b->Sort);
        self::assertSame(2, $x->Sort);
        self::assertSame(3, $y->Sort);
        self::assertSame(1, $a->Sort);
    }

    public function testSameAreaMoveToEnd(): void
    {
        // [A(1), B(2)] → move A after B (last element)
        $area = $this->createParentMock(10);
        [$a, $b] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b]);

        $result = $this->service->reorder($a, $area, $b->ID);

        self::assertTrue($result->isOk());
        self::assertSame($a, $result->unwrap());

        self::assertSame(1, $b->Sort);
        self::assertSame(2, $a->Sort);
    }

    public function testEmptyTargetAreaCrossArea(): void
    {
        // Move element to empty target area → becomes sole member with Sort=1
        // Source area 10: [Element(1)] → empty after move
        $targetArea = $this->createParentMock(20);
        $element = $this->createElementMock(1, 1, 10);

        $this->repository->method('findByParentIds')->willReturnCallback(
            static fn (array $ids, string $parentClass): array => match ($ids) {
                [20] => [],
                [10] => [$element],
                default => [],
            },
        );

        $result = $this->service->reorder($element, $targetArea, null);

        self::assertTrue($result->isOk());
        self::assertSame($element, $result->unwrap());

        self::assertSame(20, $element->ParentID);
        self::assertSame(1, $element->Sort);
    }

    public function testMoveToFirstPosition(): void
    {
        // [A(1), B(2), C(3)] → move C to first (afterElementId=null)
        // Expected: [C(1), A(2), B(3)]
        $area = $this->createParentMock(10);
        [$a, $b, $c] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
            ['id' => 3, 'sort' => 3, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b, $c]);

        $result = $this->service->reorder($c, $area, null);

        self::assertTrue($result->isOk());
        self::assertSame($c, $result->unwrap());

        self::assertSame(1, $c->Sort);
        self::assertSame(2, $a->Sort);
        self::assertSame(3, $b->Sort);
    }

    public function testCrossAreaMoveToMiddle(): void
    {
        // Source area 10: [A(1), B(2)] → move B to target area 20 after X
        // Target area 20: [X(1), Y(2)] → [X(1), B(2), Y(3)]
        // Source area 10: [A(1)] → no gaps
        $targetArea = $this->createParentMock(20);

        $a = $this->createElementMock(1, 1, 10);
        $b = $this->createElementMock(2, 2, 10);
        $x = $this->createElementMock(3, 1, 20);
        $y = $this->createElementMock(4, 2, 20);

        $this->repository->method('findByParentIds')->willReturnCallback(
            static fn (array $ids, string $parentClass): array => match ($ids) {
                [20] => [$x, $y],
                [10] => [$a, $b],
                default => [],
            },
        );

        $result = $this->service->reorder($b, $targetArea, $x->ID);

        self::assertTrue($result->isOk());
        self::assertSame($b, $result->unwrap());

        self::assertSame(20, $b->ParentID);
        self::assertSame(1, $x->Sort);
        self::assertSame(2, $b->Sort);
        self::assertSame(3, $y->Sort);
    }

    public function testSameAreaSingleElementNoOp(): void
    {
        // [A(1)] → move A to first (only element, already there)
        $area = $this->createParentMock(10);
        $a = $this->createElementMock(1, 1, 10);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a]);

        $result = $this->service->reorder($a, $area, null);

        self::assertTrue($result->isOk());
        self::assertSame($a, $result->unwrap());
        self::assertSame(1, $a->Sort);
    }

    public function testSameAreaMoveDoesNotTouchChildAreaElements(): void
    {
        // Container element (Section) with children in ChildArea(50).
        // Reorder Section within its parent area(10) — children must be unaffected.
        // [A(1), Section(2)] → move Section to first → [Section(1), A(2)]
        $area = $this->createParentMock(10);
        $a = $this->createElementMock(1, 1, 10);
        $section = $this->createElementMock(2, 2, 10);

        // Repository must only be queried for the target area (10), never for
        // the container's child area (50) or any other area.
        $this->repository->expects($this->once())
            ->method('findByParentIds')
            ->with([10], $this->anything())
            ->willReturn([$a, $section]);

        $result = $this->service->reorder($section, $area, null);

        self::assertTrue($result->isOk());
        self::assertSame($section, $result->unwrap());

        self::assertSame(1, $section->Sort);
        self::assertSame(2, $a->Sort);
    }

    public function testCrossAreaMoveDoesNotTouchChildAreaElements(): void
    {
        // Container element (Section) with children in ChildArea(50).
        // Move Section from area(10) to area(20) — children must be unaffected.
        // Target area 20: [X(1)] → [Section(1), X(2)]
        // Source area 10: [] → empty after move (was only element)
        $targetArea = $this->createParentMock(20);
        $section = $this->createElementMock(2, 1, 10);
        $x = $this->createElementMock(3, 1, 20);

        // Repository queried twice: target area (20) then source area (10).
        // Never for child area (50).
        $this->repository->expects($this->exactly(2))
            ->method('findByParentIds')
            ->willReturnCallback(
                static fn (array $ids, string $parentClass): array => match ($ids) {
                    [20] => [$x],
                    [10] => [$section],
                    default => [],
                },
            );

        $result = $this->service->reorder($section, $targetArea, null);

        self::assertTrue($result->isOk());
        self::assertSame($section, $result->unwrap());

        self::assertSame(20, $section->ParentID);
        self::assertSame(1, $section->Sort);
        self::assertSame(2, $x->Sort);
    }

    public function testAfterElementNotFoundReturnsError(): void
    {
        // Pass a non-existent afterElementId → Result::fail
        $area = $this->createParentMock(10);
        [$a, $b] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b]);

        $result = $this->service->reorder($a, $area, 999);

        self::assertTrue($result->isErr());
        self::assertSame('afterElementID', $result->errors()[0]->field);
    }

    public function testCrossAreaMoveClosesSortGapsInSourceArea(): void
    {
        // Source area 10: [A(1), B(2), C(3)] → move B to target area 20
        // Target area 20: [] → [B(1)]
        // Source area 10: [A(1), C(3)] → C re-sorts to 2, appears in dirty list
        $targetArea = $this->createParentMock(20);

        $a = $this->createElementMock(1, 1, 10);
        $b = $this->createElementMock(2, 2, 10);
        $c = $this->createElementMock(3, 3, 10);

        $this->repository->method('findByParentIds')->willReturnCallback(
            static fn (array $ids, string $parentClass): array => match ($ids) {
                [20] => [],
                [10] => [$a, $b, $c],
                default => [],
            },
        );

        $result = $this->service->reorder($b, $targetArea, null);

        self::assertTrue($result->isOk());
        self::assertSame($b, $result->unwrap());

        self::assertSame(20, $b->ParentID);
        self::assertSame(1, $b->Sort);
        self::assertSame(1, $a->Sort);
        self::assertSame(2, $c->Sort);
    }

    public function testSameAreaMoveAfterSpecificElement(): void
    {
        // [A(1), B(2), C(3), D(4), E(5)] → move B after C
        // Expected: [A(1), C(2), B(3), D(4), E(5)]
        $area = $this->createParentMock(10);
        [$a, $b, $c, $d, $e] = $this->createElementMocks([
            ['id' => 1, 'sort' => 1, 'parentId' => 10],
            ['id' => 2, 'sort' => 2, 'parentId' => 10],
            ['id' => 3, 'sort' => 3, 'parentId' => 10],
            ['id' => 4, 'sort' => 4, 'parentId' => 10],
            ['id' => 5, 'sort' => 5, 'parentId' => 10],
        ]);

        $this->repository->method('findByParentIds')->with([10], $this->anything())->willReturn([$a, $b, $c, $d, $e]);

        $result = $this->service->reorder($b, $area, $c->ID);

        self::assertTrue($result->isOk());
        self::assertSame($b, $result->unwrap());

        self::assertSame(1, $a->Sort);
        self::assertSame(2, $c->Sort);
        self::assertSame(3, $b->Sort);
        self::assertSame(4, $d->Sort);
        self::assertSame(5, $e->Sort);
    }

    public function testValidationFailureShortCircuitsReorder(): void
    {
        $validator = $this->createMock(ReorderValidatorInterface::class);
        $validator->method('validate')
            ->willReturn(Result::fail(new ValidationError(message: 'Not allowed.', field: 'placement')));

        $repository = $this->createMock(GridElementRepositoryInterface::class);
        $repository->expects($this->never())->method('findByParentIds');

        $service = new ReorderService($validator, $repository);

        $element = $this->createElementMock(1, 1, 10);
        $area = $this->createParentMock(20);

        $result = $service->reorder($element, $area, null);

        self::assertTrue($result->isErr());
        self::assertSame('Not allowed.', $result->errors()[0]->message);
        self::assertSame('placement', $result->errors()[0]->field);
    }

    /**
     * @param list<array{id: int, sort: int, parentId: int}> $specs
     * @return list<GridElement&MockObject>
     */
    private function createElementMocks(array $specs): array
    {
        return array_map(
            fn (array $spec): GridElement&MockObject => $this->createElementMock($spec['id'], $spec['sort'], $spec['parentId']),
            $specs,
        );
    }

    private function createElementMock(int $id, int $sort, int $parentId, string $parentClass = DataObject::class): GridElement&MockObject
    {
        $element = $this->createMock(GridElement::class);

        $fields = ['ID' => $id, 'Sort' => $sort, 'ParentID' => $parentId, 'ParentClass' => $parentClass];

        $element->method('__get')->willReturnCallback(
            static function (string $prop) use (&$fields): mixed {
                return $fields[$prop] ?? null;
            },
        );

        $element->method('__set')->willReturnCallback(
            static function (string $prop, mixed $value) use (&$fields): void {
                $fields[$prop] = $value;
            },
        );

        return $element;
    }

    private function createParentMock(int $id): DataObject&MockObject
    {
        $area = $this->createMock(DataObject::class);

        $fields = ['ID' => $id];

        $area->method('__get')->willReturnCallback(
            static function (string $prop) use (&$fields): mixed {
                return $fields[$prop] ?? null;
            },
        );

        return $area;
    }
}

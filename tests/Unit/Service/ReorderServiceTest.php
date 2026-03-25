<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Contract\ReorderExecutorInterface;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Service\ReorderService;

#[CoversClass(ReorderService::class)]
final class ReorderServiceTest extends TestCase
{
    private ReorderValidatorInterface&MockObject $validator;

    private ReorderExecutorInterface&MockObject $executor;

    private ReorderService $service;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ReorderValidatorInterface::class);
        $this->executor = $this->createMock(ReorderExecutorInterface::class);
        $this->service = new ReorderService($this->validator, $this->executor);
    }

    public function testHappyPathCallsValidatorThenExecutorThenWritesDirtyElements(): void
    {
        $element = $this->createMock(GridElement::class);
        $area = $this->createMock(DataObject::class);

        $dirtyElement = $this->createMock(GridElement::class);
        $dirtyElement->expects($this->once())->method('write');

        $this->validator->expects($this->once())
            ->method('validate')
            ->with($element, $area)
            ->willReturn(Result::ok($element));

        $this->executor->expects($this->once())
            ->method('execute')
            ->with($element, $area, 42)
            ->willReturn(Result::ok([$dirtyElement]));

        $result = $this->service->reorder($element, $area, 42);

        self::assertTrue($result->isOk());
        self::assertSame($element, $result->unwrap());
    }

    public function testValidationFailureShortCircuitsExecution(): void
    {
        $element = $this->createMock(GridElement::class);
        $area = $this->createMock(DataObject::class);

        $this->validator->method('validate')
            ->willReturn(Result::fail(new ValidationError(message: 'Not allowed.')));

        $this->executor->expects($this->never())->method('execute');

        $result = $this->service->reorder($element, $area, null);

        self::assertTrue($result->isErr());
        self::assertSame('Not allowed.', $result->errors()[0]->message);
    }

    public function testValidationErrorsPropagatedToResult(): void
    {
        $element = $this->createMock(GridElement::class);
        $area = $this->createMock(DataObject::class);

        $this->validator->method('validate')
            ->willReturn(Result::fail(
                new ValidationError(message: 'First error.', field: 'placement'),
                new ValidationError(message: 'Second error.'),
            ));

        $result = $this->service->reorder($element, $area, null);

        self::assertTrue($result->isErr());
        self::assertCount(2, $result->errors());
        self::assertSame('First error.', $result->errors()[0]->message);
        self::assertSame('placement', $result->errors()[0]->field);
        self::assertSame('Second error.', $result->errors()[1]->message);
    }

    public function testWriteFailurePropagated(): void
    {
        $element = $this->createMock(GridElement::class);
        $area = $this->createMock(DataObject::class);

        $dirtyElement = $this->createMock(GridElement::class);

        $validationResult = $this->createMock(ValidationResult::class);
        $validationResult->method('getMessages')->willReturn([
            ['message' => 'Write failed.', 'fieldName' => ''],
        ]);
        $exception = $this->createMock(ValidationException::class);
        $exception->method('getResult')->willReturn($validationResult);

        $dirtyElement->method('write')->willThrowException($exception);

        $this->validator->method('validate')->willReturn(Result::ok($element));
        $this->executor->method('execute')->willReturn(Result::ok([$dirtyElement]));

        $result = $this->service->reorder($element, $area, null);

        self::assertTrue($result->isErr());
        self::assertSame('Write failed.', $result->errors()[0]->message);
    }

    public function testEmptyDirtyListReturnsOk(): void
    {
        $element = $this->createMock(GridElement::class);
        $area = $this->createMock(DataObject::class);

        $this->validator->method('validate')->willReturn(Result::ok($element));
        $this->executor->method('execute')->willReturn(Result::ok([]));

        $result = $this->service->reorder($element, $area, 5);

        self::assertTrue($result->isOk());
        self::assertSame($element, $result->unwrap());
    }

    public function testExecutorFailurePropagated(): void
    {
        $element = $this->createMock(GridElement::class);
        $area = $this->createMock(DataObject::class);

        $this->validator->method('validate')->willReturn(Result::ok($element));

        $this->executor->method('execute')
            ->willReturn(Result::fail(new ValidationError(
                message: 'The reference element no longer exists in the target area.',
                field: 'afterElementID',
            )));

        $result = $this->service->reorder($element, $area, 999);

        self::assertTrue($result->isErr());
        self::assertSame('afterElementID', $result->errors()[0]->field);
    }
}

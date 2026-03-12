<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\FixturePostAction;

#[CoversClass(FixturePostAction::class)]
final class FixturePostActionTest extends TestCase
{
    // ── fromConfig: validation ──────────────────────────────────────

    public function testFromConfigThrowsWhenActionMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "action", "class", and "identifier" keys');

        FixturePostAction::fromConfig([
            'class' => 'SomeClass',
            'identifier' => 'x',
        ]);
    }

    public function testFromConfigThrowsWhenClassMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "action", "class", and "identifier" keys');

        FixturePostAction::fromConfig([
            'action' => 'publish_recursive',
            'identifier' => 'x',
        ]);
    }

    public function testFromConfigThrowsWhenIdentifierMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "action", "class", and "identifier" keys');

        FixturePostAction::fromConfig([
            'action' => 'publish_recursive',
            'class' => 'SomeClass',
        ]);
    }

    #[DataProvider('nonStringConfigValueProvider')]
    public function testFromConfigThrowsWhenKeyIsNotString(string $key, mixed $value): void
    {
        $config = [
            'action' => 'publish_recursive',
            'class' => 'SomeClass',
            'identifier' => 'x',
        ];
        $config[$key] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "action", "class", and "identifier" keys');

        FixturePostAction::fromConfig($config);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonStringConfigValueProvider(): array
    {
        return [
            'action is int' => ['action', 42],
            'action is null' => ['action', null],
            'action is bool' => ['action', true],
            'class is int' => ['class', 99],
            'class is null' => ['class', null],
            'identifier is int' => ['identifier', 1],
            'identifier is null' => ['identifier', null],
        ];
    }

    public function testFromConfigThrowsForUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown post-action "delete_all"');

        FixturePostAction::fromConfig([
            'action' => 'delete_all',
            'class' => DataObject::class,
            'identifier' => 'x',
        ]);
    }

    public function testFromConfigErrorMessageListsValidActions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('publish_recursive, unpublish, modify, attach_image');

        FixturePostAction::fromConfig([
            'action' => 'invalid',
            'class' => DataObject::class,
            'identifier' => 'x',
        ]);
    }

    // ── fromConfig: successful creation ─────────────────────────────

    #[DataProvider('validActionProvider')]
    public function testFromConfigCreatesInstanceForValidAction(string $action): void
    {
        $result = FixturePostAction::fromConfig([
            'action' => $action,
            'class' => DataObject::class,
            'identifier' => 'test_record',
        ]);

        $this->assertSame($action, $result->action);
        $this->assertSame(DataObject::class, $result->class);
        $this->assertSame('test_record', $result->identifier);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validActionProvider(): array
    {
        return [
            'publish_recursive' => ['publish_recursive'],
            'unpublish' => ['unpublish'],
            'modify' => ['modify'],
            'attach_image' => ['attach_image'],
        ];
    }

    public function testFromConfigDefaultsFieldsToEmptyArray(): void
    {
        $result = FixturePostAction::fromConfig([
            'action' => 'modify',
            'class' => DataObject::class,
            'identifier' => 'x',
        ]);

        $this->assertSame([], $result->fields);
    }

    public function testFromConfigPreservesFields(): void
    {
        $fields = ['Title' => 'Updated', 'Sort' => 5, 'Visible' => true];

        $result = FixturePostAction::fromConfig([
            'action' => 'modify',
            'class' => DataObject::class,
            'identifier' => 'x',
            'fields' => $fields,
        ]);

        $this->assertSame($fields, $result->fields);
    }

    // ── apply: versioned extension check ────────────────────────────

    #[DataProvider('versionedActionProvider')]
    public function testApplyThrowsWhenVersionedActionCalledOnNonVersionedRecord(string $action): void
    {
        $postAction = new FixturePostAction(
            action: $action,
            class: DataObject::class,
            identifier: 'test',
        );

        $record = $this->createMock(DataObject::class);
        $record->method('hasExtension')->with(Versioned::class)->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Post-action "%s" requires Versioned extension', $action));

        $postAction->apply($record);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function versionedActionProvider(): array
    {
        return [
            'publish_recursive' => ['publish_recursive'],
            'unpublish' => ['unpublish'],
        ];
    }

    #[DataProvider('versionedActionProvider')]
    public function testApplyErrorMessageIncludesRecordClass(string $action): void
    {
        $postAction = new FixturePostAction(
            action: $action,
            class: DataObject::class,
            identifier: 'test',
        );

        $record = $this->createMock(DataObject::class);
        $record->method('hasExtension')->with(Versioned::class)->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/but MockObject_DataObject_\w+ does not have it/');

        $postAction->apply($record);
    }

    // ── apply: publish_recursive ────────────────────────────────────

    public function testApplyPublishRecursiveCallsPublishRecursive(): void
    {
        $postAction = new FixturePostAction(
            action: 'publish_recursive',
            class: DataObject::class,
            identifier: 'test',
        );

        $record = $this->createMock(DataObject::class);
        $record->method('hasExtension')->with(Versioned::class)->willReturn(true);
        $record->expects($this->once())->method('__call')
            ->with('publishRecursive')
            ->willReturn(true);

        $postAction->apply($record);
    }

    // ── apply: unpublish ────────────────────────────────────────────

    public function testApplyUnpublishCallsDoUnpublish(): void
    {
        $postAction = new FixturePostAction(
            action: 'unpublish',
            class: DataObject::class,
            identifier: 'test',
        );

        $record = $this->createMock(DataObject::class);
        $record->method('hasExtension')->with(Versioned::class)->willReturn(true);
        $record->expects($this->once())->method('__call')
            ->with('doUnpublish')
            ->willReturn(true);

        $postAction->apply($record);
    }

    // ── apply: modify ───────────────────────────────────────────────

    public function testApplyModifyDoesNotRequireVersionedExtension(): void
    {
        $postAction = new FixturePostAction(
            action: 'modify',
            class: DataObject::class,
            identifier: 'test',
            fields: ['Title' => 'Updated'],
        );

        $record = $this->createMock(DataObject::class);
        $record->expects($this->never())->method('hasExtension');
        $record->expects($this->once())->method('setField')->with('Title', 'Updated')->willReturnSelf();
        $record->expects($this->once())->method('write');

        $postAction->apply($record);
    }

    public function testApplyModifySetsMultipleFieldsAndWrites(): void
    {
        $postAction = new FixturePostAction(
            action: 'modify',
            class: DataObject::class,
            identifier: 'test',
            fields: ['Title' => 'New Title', 'Sort' => 5, 'Content' => 'Body text'],
        );

        $record = $this->createMock(DataObject::class);

        $setFieldCalls = [];
        $record->expects($this->exactly(3))->method('setField')
            ->willReturnCallback(function (string $field, mixed $value) use ($record, &$setFieldCalls): DataObject {
                $setFieldCalls[] = [$field, $value];

                return $record;
            });
        $record->expects($this->once())->method('write');

        $postAction->apply($record);

        $this->assertSame([
            ['Title', 'New Title'],
            ['Sort', 5],
            ['Content', 'Body text'],
        ], $setFieldCalls);
    }

    public function testApplyModifyWithNoFieldsStillCallsWrite(): void
    {
        $postAction = new FixturePostAction(
            action: 'modify',
            class: DataObject::class,
            identifier: 'test',
            fields: [],
        );

        $record = $this->createMock(DataObject::class);
        $record->expects($this->never())->method('setField');
        $record->expects($this->once())->method('write');

        $postAction->apply($record);
    }

    // ── apply: attach_image validation ──────────────────────────────

    public function testApplyAttachImageDoesNotRequireVersionedExtension(): void
    {
        $postAction = new FixturePostAction(
            action: 'attach_image',
            class: DataObject::class,
            identifier: 'test',
            fields: [],
        );

        $record = $this->createMock(DataObject::class);
        $record->expects($this->never())->method('hasExtension');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source" in fields');

        $postAction->apply($record);
    }

    public function testApplyAttachImageThrowsWhenFieldsMissing(): void
    {
        $postAction = new FixturePostAction(
            action: 'attach_image',
            class: DataObject::class,
            identifier: 'test',
            fields: [],
        );

        $record = $this->createMock(DataObject::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source" in fields');

        $postAction->apply($record);
    }

    public function testApplyAttachImageThrowsWhenRelationEmpty(): void
    {
        $postAction = new FixturePostAction(
            action: 'attach_image',
            class: DataObject::class,
            identifier: 'test',
            fields: ['relation' => '', 'source' => 'vendor/module:path/to/image.png'],
        );

        $record = $this->createMock(DataObject::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source" in fields');

        $postAction->apply($record);
    }

    public function testApplyAttachImageThrowsWhenSourceEmpty(): void
    {
        $postAction = new FixturePostAction(
            action: 'attach_image',
            class: DataObject::class,
            identifier: 'test',
            fields: ['relation' => 'Image', 'source' => ''],
        );

        $record = $this->createMock(DataObject::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source" in fields');

        $postAction->apply($record);
    }

    // ── Constructor: readonly properties ────────────────────────────

    public function testConstructorSetsPublicProperties(): void
    {
        $fields = ['Title' => 'Test'];
        $action = new FixturePostAction(
            action: 'modify',
            class: DataObject::class,
            identifier: 'my_record',
            fields: $fields,
        );

        $this->assertSame('modify', $action->action);
        $this->assertSame(DataObject::class, $action->class);
        $this->assertSame('my_record', $action->identifier);
        $this->assertSame($fields, $action->fields);
    }

    public function testConstructorDefaultsFieldsToEmptyArray(): void
    {
        $action = new FixturePostAction(
            action: 'publish_recursive',
            class: DataObject::class,
            identifier: 'test',
        );

        $this->assertSame([], $action->fields);
    }
}

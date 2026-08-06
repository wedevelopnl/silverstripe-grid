<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\Transactional;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;

/**
 * Pins the transaction semantics of {@see Transactional::run()} against a real
 * connection. The unit test only reaches the `$conn === null` early return, so
 * the commit / rollback / re-throw arms need a database to be observable.
 */
#[CoversClass(Transactional::class)]
final class TransactionalTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
    }

    public function testCommitsWritesWhenOperationReturnsOk(): void
    {
        $result = Transactional::run(static function (): Result {
            $section = Section::create();
            $section->Title = 'committed';
            $section->write();

            return Result::ok((int) $section->ID);
        });

        self::assertTrue($result->isOk());
        self::assertInstanceOf(Section::class, Section::get()->byID($result->unwrap()));
    }

    public function testRollsBackWritesWhenOperationReturnsErr(): void
    {
        $writtenId = null;

        $result = Transactional::run(static function () use (&$writtenId): Result {
            $section = Section::create();
            $section->Title = 'rolled back';
            $section->write();
            $writtenId = (int) $section->ID;

            return Result::fail(new ValidationError('nope'));
        });

        self::assertTrue($result->isErr());
        self::assertNotNull($writtenId);
        self::assertNull(Section::get()->byID($writtenId), 'an err Result must roll the write back');
    }

    public function testReturnsTheErrResultRatherThanThrowingTheRollbackSentinel(): void
    {
        $result = Transactional::run(static fn (): Result => Result::fail(new ValidationError('nope')));

        self::assertTrue($result->isErr());
        self::assertSame('nope', $result->errors()[0]->message);
    }

    public function testRethrowsExceptionsRaisedByTheOperation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        // Bound, never read: run() is #[\NoDiscard] and PHP 8.5 warns on a
        // discarded call even when it throws, which failOnWarning turns red.
        // The (void) cast the warning suggests is 8.5-only syntax and would
        // break the 8.3 floor, so bind instead.
        $neverReturned = Transactional::run(static function (): Result {
            throw new RuntimeException('boom');
        });
    }
}

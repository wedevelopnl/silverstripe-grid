<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Value\WriteResult;

#[CoversClass(WriteResult::class)]
final class WriteResultTest extends SapphireTest
{
    public function testFromReturnsOkOnSuccess(): void
    {
        $result = WriteResult::from(static fn (): int => 42);

        self::assertTrue($result->isOk());
        self::assertSame(42, $result->unwrap());
    }

    public function testFromCatchesValidationException(): void
    {
        $result = WriteResult::from(static function (): never {
            throw new ValidationException('Write failed');
        });

        self::assertTrue($result->isErr());
    }

    public function testTranslatesExceptionMessages(): void
    {
        $validationResult = ValidationResult::create();
        $validationResult->addFieldError('Title', 'Title is required');

        $result = WriteResult::from(static function () use ($validationResult): never {
            throw new ValidationException($validationResult);
        });

        self::assertTrue($result->isErr());
        $errors = $result->errors();
        self::assertCount(1, $errors);
        self::assertSame('Title is required', $errors[0]->message);
        self::assertSame('Title', $errors[0]->field);
    }

    public function testTranslatesAllFieldErrors(): void
    {
        // Pins that every message is translated into its own ValidationError —
        // a mutant that emits only the first (or last) entry would fail the count.
        $validationResult = ValidationResult::create();
        $validationResult->addFieldError('Title', 'Title is required');
        $validationResult->addFieldError('Zone', 'Zone is invalid');

        $result = WriteResult::from(static function () use ($validationResult): never {
            throw new ValidationException($validationResult);
        });

        self::assertTrue($result->isErr());
        $errors = $result->errors();
        self::assertCount(2, $errors);

        $messages = array_map(static fn ($error): string => $error->message, $errors);
        self::assertContains('Title is required', $messages);
        self::assertContains('Zone is invalid', $messages);
    }

    public function testEmptyExceptionMessagesFallback(): void
    {
        // ValidationResult with no messages
        $validationResult = ValidationResult::create();

        $result = WriteResult::from(static function () use ($validationResult): never {
            throw new ValidationException($validationResult);
        });

        self::assertTrue($result->isErr());
        $errors = $result->errors();
        self::assertCount(1, $errors);
        self::assertSame('Validation failed.', $errors[0]->message);
    }

    public function testNonValidationExceptionBubbles(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a validation error');

        // Bound, never read: from() is #[\NoDiscard] and PHP 8.5 warns on a
        // discarded call even when it throws, which failOnWarning turns red.
        // The (void) cast the warning suggests is 8.5-only syntax and would
        // break the 8.3 floor, so bind instead.
        $neverReturned = WriteResult::from(static function (): never {
            throw new \RuntimeException('Not a validation error');
        });
    }

    public function testLogsRawValidationExceptionServerSide(): void
    {
        // The raw framework detail must reach the log even though only the composed,
        // translated messages reach the client. Without this assertion the debug()
        // call could be dropped silently while every other test stayed green.
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $level . ':' . (string) ($context['message'] ?? '');
            }
        };
        Injector::inst()->registerService($logger, LoggerInterface::class);

        $result = WriteResult::from(static function (): never {
            throw new ValidationException('Raw framework detail');
        });

        self::assertTrue($result->isErr());
        self::assertContains('debug:Raw framework detail', $logger->records);
    }
}

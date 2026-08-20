<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use Psr\Log\NullLogger;

/**
 * Test double that records every log entry for assertion.
 *
 * Interpolation follows PSR-3 placeholder semantics; non-scalar context
 * values render as their type name so array/object context values cannot
 * fatal the assertion helper.
 */
final class RecordingLogger extends NullLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $messages = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Raw entries logged at a level.
     *
     * @param non-empty-string $level
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function entriesAt(string $level): array
    {
        return \array_values(\array_filter(
            $this->messages,
            static fn (array $entry): bool => $entry['level'] === $level,
        ));
    }

    /**
     * Messages logged at a level, with PSR-3 placeholders interpolated.
     *
     * @param non-empty-string $level
     *
     * @return list<string>
     */
    public function messagesAt(string $level): array
    {
        $result = [];
        foreach ($this->entriesAt($level) as $entry) {
            $replacements = [];
            foreach ($entry['context'] as $key => $value) {
                $replacements['{' . $key . '}'] = \is_scalar($value) ? (string) $value : \get_debug_type($value);
            }

            $result[] = \strtr($entry['message'], $replacements);
        }

        return $result;
    }
}

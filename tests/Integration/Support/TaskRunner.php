<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drives a BuildTask the way `sake` would, capturing exit code and output.
 */
final class TaskRunner
{
    /**
     * Run non-interactively (prompts are skipped or must be answered via options).
     *
     * @param array<string, mixed> $options
     *
     * @return array{exitCode: int, output: string}
     */
    public static function run(BuildTask $task, array $options = []): array
    {
        return self::execute($task, $options, null);
    }

    /**
     * Run with an in-memory STDIN stream answering interactive prompts
     * (e.g. "y\n" for a confirmation gate).
     *
     * @param array<string, mixed> $options
     *
     * @return array{exitCode: int, output: string}
     */
    public static function runInteractive(BuildTask $task, array $options, string $answer): array
    {
        return self::execute($task, $options, $answer);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{exitCode: int, output: string}
     */
    private static function execute(BuildTask $task, array $options, ?string $answer): array
    {
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));

        if ($answer === null) {
            $input->setInteractive(false);
        } else {
            $input->setInteractive(true);
            $stream = \fopen('php://memory', 'r+');
            \assert(\is_resource($stream));
            \fwrite($stream, $answer);
            \rewind($stream);
            $input->setStream($stream);
        }

        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        return [
            'exitCode' => $task->execute($input, $output),
            'output' => $buffered->fetch(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

/**
 * Generates "copy" titles for duplicated elements.
 *
 * "My Block" → "My Block copy"
 * "My Block copy" → "My Block copy 2"
 * "My Block copy 2" → "My Block copy 3"
 */
final class TitleGenerator
{
    /**
     * @param non-empty-string $title
     * @return non-empty-string
     */
    public static function generateCopyTitle(string $title): string
    {
        if (preg_match('/^(?<base>.*)\scopy(?:\s(?<num>\d+))?$/', $title, $m) === 1) {
            if (isset($m['num'])) {
                return $m['base'] . ' copy ' . ((int) $m['num'] + 1);
            }

            return $title . ' 2';
        }

        return $title . ' copy';
    }
}

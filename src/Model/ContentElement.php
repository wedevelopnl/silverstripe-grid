<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;

/**
 * Base class for leaf content elements that render visible content.
 *
 * Content elements sit inside columns and produce template output.
 * They are never containers — they do not hold child elements.
 */
class ContentElement extends GridElement
{
    private static string $table_name = 'ContentElement';

    private static string $singular_name = 'Content element';

    private static string $plural_name = 'Content elements';

    private static string $icon = 'font-icon-block-content';

    /** @var array<string, string> */
    private static array $db = [
        'HTML' => 'HTMLText',
    ];

    private static string $class_description = '';

    /** Word budget for the default editor-card summary derived from the HTML field. */
    private static int $summary_word_count = 20;

    /**
     * Default summary: a plain-text preview of the HTML field, truncated to
     * `summary_word_count` words. Returns an empty string when HTML is empty —
     * GridNode drops that from the payload, so the card hides the line.
     */
    #[Override]
    public function getSummary(): ?string
    {
        $wordCount = (int) static::config()->get('summary_word_count');

        return (string) $this->dbObject('HTML')->Summary($wordCount);
    }
}

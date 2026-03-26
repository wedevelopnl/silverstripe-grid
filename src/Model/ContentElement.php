<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

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

    /** Whether content of this type should be included in search indexes. */
    private static bool $search_indexable = true;

    /** Whether this element type should be indexed for site search. */
    public function getSearchIndexable(): bool
    {
        return (bool) static::config()->get('search_indexable');
    }
}

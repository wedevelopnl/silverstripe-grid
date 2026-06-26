<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class CreateElementRequest
{
    /**
     * Field order mirrors {@see \WeDevelop\Grid\Service\GridElementService::createElement()}
     * so the controller can forward the DTO with the same positional layout
     * and a future rename catches both sides at once.
     *
     * @param non-empty-string $zone Non-empty by construction: the only caller
     *     ({@see \WeDevelop\Grid\Service\RequestBodyParser::parseCreateBody})
     *     rejects an empty zone before building this request. (Section.Zone may
     *     be '' elsewhere in the domain, but never on the create path.)
     * @param positive-int|null $insertAfterElementID Place the new element directly after this sibling; null = append at the end
     * @param bool $insertAtStart Place the new element before all existing siblings. Mutually exclusive with $insertAfterElementID.
     */
    public function __construct(
        public NodeRef $parent,
        public ContainerType $containerType,
        public string $zone,
        public ?int $insertAfterElementID,
        public bool $insertAtStart = false,
    ) {
    }
}

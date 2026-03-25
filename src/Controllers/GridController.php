<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Controllers;

use Override;
use InvalidArgumentException;
use stdClass;
use SilverStripe\Admin\AdminController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\Viewport;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Service\ElementPersistenceService;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Service\GridSettingsCompactor;
use WeDevelop\Grid\Service\ReorderService;
use WeDevelop\Grid\Service\RequestBodyParser;
use WeDevelop\Grid\Service\TitleGenerator;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Value\GridNode;

/**
 * @phpstan-type AdapterConfig array{viewports: list<array{key: string, label: string}>, defaultViewport: string, columnCount: positive-int, rowClasses: string, offsetStrategy: 'margin'|'grid-placement', baseWidthClasses: stdClass&object{'1': string, '2': string, '3': string, '4': string, '5': string, '6': string, '7': string, '8': string, '9': string, '10': string, '11': string, '12': string}, baseOffsetClasses: stdClass&object{'0': string, '1': string, '2': string, '3': string, '4': string, '5': string, '6': string, '7': string, '8': string, '9': string, '10': string, '11': string}}
 *
 * @property GridElementRepositoryInterface $elementRepository
 * @property GridTreeBuilder $treeBuilder
 * @property ElementPersistenceService $persistenceService
 * @property ReorderService $reorderService
 * @property GridAdapterInterface $gridAdapter
 * @property RequestBodyParser $requestBodyParser
 */
class GridController extends AdminController
{
    private static string $url_segment = 'grid';

    private static string $required_permission_codes = 'CMS_ACCESS';

    /** @var array<string, string> */
    private static array $dependencies = [
        'elementRepository' => '%$' . GridElementRepositoryInterface::class,
        'treeBuilder' => '%$' . GridTreeBuilder::class,
        'persistenceService' => '%$' . ElementPersistenceService::class,
        'reorderService' => '%$' . ReorderService::class,
        'gridAdapter' => '%$' . GridAdapterInterface::class,
        'requestBodyParser' => '%$' . RequestBodyParser::class,
    ];

    public GridElementRepositoryInterface $elementRepository;

    public GridTreeBuilder $treeBuilder;

    public ElementPersistenceService $persistenceService;

    public ReorderService $reorderService;

    public GridAdapterInterface $gridAdapter;

    public RequestBodyParser $requestBodyParser;

    /** @var array<string, string> */
    private static array $url_handlers = [
        'GET api/readTree/$PageID!/$Zone!' => 'apiReadTree',
        'POST api/create' => 'apiCreate',
        'POST api/createContent' => 'apiCreateContent',
        'PATCH api/publish' => 'apiPublish',
        'PATCH api/unpublish' => 'apiUnpublish',
        'DELETE api/delete' => 'apiDelete',
        'POST api/duplicate' => 'apiDuplicate',
        'POST api/duplicateTo' => 'apiDuplicateTo',
        'PATCH api/reorder' => 'apiReorder',
        'PATCH api/updateGridSettings' => 'apiUpdateGridSettings',
        'GET api/acceptableContainers/$PageID!/$Zone!/$ElementType!' => 'apiAcceptableContainers',
        'GET api/zones/$PageID!' => 'apiZones',
        'GET api/pages' => 'apiPages',
    ];

    /** @var list<string> */
    private static array $allowed_actions = [
        'apiReadTree',
        'apiCreate',
        'apiCreateContent',
        'apiPublish',
        'apiUnpublish',
        'apiDelete',
        'apiDuplicate',
        'apiDuplicateTo',
        'apiReorder',
        'apiUpdateGridSettings',
        'apiAcceptableContainers',
        'apiZones',
        'apiPages',
    ];

    #[Override]
    protected function init(): void
    {
        parent::init();

        if ($this->getRequest()->httpMethod() !== 'GET'
            && !SecurityToken::inst()->checkRequest($this->getRequest())
        ) {
            $this->jsonError(400);
        }
    }

    public function apiReadTree(HTTPRequest $request): HTTPResponse
    {
        $pageId = (int) $request->param('PageID');

        /** @var SiteTree|null $page */
        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        /** @var non-empty-string $zone Route pattern guarantees non-empty zone segment */
        $zone = (string) $request->param('Zone');
        $tree = $this->treeBuilder->buildForPage($page, $zone);

        return $this->jsonSuccess(200, $tree);
    }

    public function apiCreate(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseCreateBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        /** @var DataObject|null $parent */
        $parent = Versioned::withVersionedMode(static function () use ($body): ?DataObject {
            Versioned::set_stage(Versioned::DRAFT);

            // Sections live under SiteTree pages; rows and columns live under GridElements.
            // We must query the correct table because page IDs and element IDs share
            // the same numeric space and can collide.
            if ($body->containerType === ContainerType::Section) {
                return SiteTree::get()->byID($body->parentId);
            }

            return GridElement::get()->byID($body->parentId);
        });
        if ($parent === null) {
            $this->jsonError(400);
        }

        if (!$parent->canEdit()) {
            $this->jsonError(403);
        }

        /** @var GridElement $newElement */
        $newElement = Injector::inst()->create($body->containerType->toElementClass());
        if (!$newElement->canCreate(null, ['Parent' => $parent])) {
            $this->jsonError(403);
        }

        $newElement->ParentID = $body->parentId;
        $newElement->ParentClass = $parent::class;

        if ($body->containerType === ContainerType::Section) {
            $newElement->Zone = $body->zone;
        }

        $newElement->ensureSortSet();

        $result = $this->persistenceService->persistNew($newElement, $body->insertAfterElementID);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($newElement);

        return $this->jsonSuccess(204);
    }

    public function apiCreateContent(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseCreateContentBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        /** @var GridElement|null $parent */
        $parent = Versioned::withVersionedMode(static function () use ($body): ?GridElement {
            Versioned::set_stage(Versioned::DRAFT);

            return GridElement::get()->byID($body->parentId);
        });
        if ($parent === null) {
            $this->jsonError(400);
        }

        if (!$parent instanceof Column) {
            $this->jsonError(400);
        }

        if (!$parent->canEdit()) {
            $this->jsonError(403);
        }

        /** @var ContentElement $newElement */
        $newElement = Injector::inst()->create($body->className);
        if (!$newElement->canCreate(null, ['Parent' => $parent])) {
            $this->jsonError(403);
        }

        $newElement->ParentID = $body->parentId;
        $newElement->ParentClass = $parent::class;
        $newElement->ensureSortSet();

        $result = $this->persistenceService->persistNew($newElement, $body->insertAfterElementID);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($newElement);

        return $this->jsonSuccess(204);
    }

    public function apiPublish(HTTPRequest $request): HTTPResponse
    {
        $id = $this->requireElementIdFromRequest($request);
        $element = $this->requireElementWithPermission(
            $id,
            static fn (GridElement $e): bool => $e->canPublish(),
        );

        $element->publishRecursive();

        return $this->jsonSuccess(204);
    }

    public function apiUnpublish(HTTPRequest $request): HTTPResponse
    {
        $id = $this->requireElementIdFromRequest($request);
        $element = $this->requireElementWithPermission(
            $id,
            static function (GridElement $e): bool {
                $result = $e->canUnpublish();
                assert(is_bool($result));

                return $result;
            },
        );

        $element->doUnpublish();

        return $this->jsonSuccess(204);
    }

    public function apiDelete(HTTPRequest $request): HTTPResponse
    {
        $id = $this->requireElementIdFromRequest($request);
        $element = $this->requireElementWithPermission(
            $id,
            static fn (GridElement $e): bool => $e->canDelete(),
        );

        $page = $element->getPage();
        $element->doArchive();

        if ($page instanceof SiteTree) {
            $this->touchOwningPage($page);
        }

        return $this->jsonSuccess(204);
    }

    public function apiDuplicate(HTTPRequest $request): HTTPResponse
    {
        $id = $this->requireElementIdFromRequest($request);
        $element = $this->requireElementWithPermission(
            $id,
            static fn (GridElement $e): bool => $e->canCreate(),
        );

        $parent = $element->Parent();
        if ($parent === null || !$parent->exists() || !$parent->canEdit()) {
            $this->jsonError(403);
        }

        $clone = $element->duplicate(false);
        /** @var non-empty-string $cloneTitle Elements always have a title after write */
        $cloneTitle = $clone->Title ?: $element->Title ?: 'Untitled';
        $clone->Title = TitleGenerator::generateCopyTitle($cloneTitle);
        $clone->Sort = 0;
        $clone->ParentID = $element->ParentID;
        $clone->ParentClass = $element->ParentClass;

        /** @var positive-int $elementId */
        $elementId = (int) $element->ID;

        $result = $this->persistenceService->persistDuplicate($clone, $elementId);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($clone);

        return $this->jsonSuccess(204);
    }

    public function apiDuplicateTo(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseDuplicateToBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $element = $this->requireElementWithPermission(
            $body->id,
            static fn (GridElement $e): bool => $e->canCreate(),
        );

        // Sections target pages (SiteTree), all others target GridElements.
        // Must query the correct table due to ID namespace collisions.
        $isSection = $element instanceof Section;

        /** @var DataObject|null $targetParent */
        $targetParent = Versioned::withVersionedMode(static function () use ($body, $isSection): ?DataObject {
            Versioned::set_stage(Versioned::DRAFT);

            if ($isSection) {
                return SiteTree::get()->byID($body->targetParentId);
            }

            return GridElement::get()->byID($body->targetParentId);
        });

        if ($targetParent === null || !$targetParent->exists()) {
            $this->jsonError(404);
        }

        if (!$targetParent->canEdit()) {
            $this->jsonError(403);
        }

        // C1: Validate target parent belongs to the claimed page/zone
        if ($isSection) {
            // For sections, the target parent IS the page
            if ($body->targetParentId !== $body->targetPageId) {
                $this->jsonError(400);
            }
        } else {
            // For non-section elements, walk up to the page and verify ownership
            assert($targetParent instanceof GridElement);
            $owningPage = $targetParent->getPage();

            if (!$owningPage instanceof SiteTree || (int) $owningPage->ID !== $body->targetPageId) {
                $this->jsonError(400);
            }

            // Find the root section and verify its zone matches
            $ancestor = $targetParent;
            while ($ancestor instanceof GridElement && !$ancestor instanceof Section) {
                $parent = $ancestor->Parent();
                $ancestor = $parent instanceof GridElement ? $parent : null;
            }
            if ($ancestor instanceof Section && $ancestor->Zone !== $body->targetZone) {
                $this->jsonError(400);
            }
        }

        // C2: Validate hierarchy rules before deep copy
        if ($targetParent instanceof ContainerInterface) {
            $containerType = $targetParent->getContainerType();
            if (!$containerType->isChildAllowed($element::class)) {
                $this->jsonError(422, sprintf(
                    '%s cannot be placed inside %s.',
                    $element->singular_name(),
                    $targetParent->singular_name(),
                ));
            }
        }
        if ($targetParent instanceof SiteTree && $element instanceof ContainerInterface) {
            if (!$element->getContainerType()->canBeRoot()) {
                $this->jsonError(422, sprintf(
                    '%s cannot be placed at page level.',
                    $element->singular_name(),
                ));
            }
        }

        // Deep-duplicate the entire subtree (follows cascade_duplicates)
        $clone = $element->duplicate(true);

        // Re-parent to target
        $clone->ParentID = $body->targetParentId;
        $clone->ParentClass = $targetParent::class;

        // Set zone for sections
        if ($clone instanceof Section) {
            $clone->Zone = $body->targetZone;
        }

        // Generate copy title (top-level only — children keep originals)
        /** @var non-empty-string $cloneTitle */
        $cloneTitle = $clone->Title ?: $element->Title ?: 'Untitled';
        $clone->Title = TitleGenerator::generateCopyTitle($cloneTitle);

        $clone->Sort = 0;

        $result = $this->persistenceService->persistAppend($clone);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($clone);

        return $this->jsonSuccess(204);
    }

    public function apiReorder(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseReorderBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $element = $this->elementRepository->findById($body->elementID);
        if ($element === null) {
            $this->jsonError(400);
        }

        if (!$element->canEdit()) {
            $this->jsonError(403);
        }

        $targetParent = $this->resolveParentRecord($body->targetParentId, $element);
        if (!$targetParent instanceof DataObject) {
            $this->jsonError(400);
        }

        if (!$targetParent->canEdit()) {
            $this->jsonError(403);
        }

        /** @var positive-int $sourceParentId */
        $sourceParentId = (int) $element->ParentID;
        $isCrossParent = $sourceParentId !== $body->targetParentId;

        if ($isCrossParent) {
            $sourceParent = $element->Parent();
            if ($sourceParent === null || !$sourceParent->exists() || !$sourceParent->canEdit()) {
                $this->jsonError(403);
            }
        }

        $result = $this->reorderService->reorder($element, $targetParent, $body->afterElementID);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($element);

        return $this->jsonSuccess(204);
    }

    public function apiUpdateGridSettings(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseUpdateGridSettingsBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $element = $this->requireElementWithPermission(
            $body->id,
            static fn (GridElement $e): bool => $e->canEdit(),
        );

        if (!$element instanceof Column) {
            $this->jsonError(400);
        }

        $compactor = new GridSettingsCompactor($this->gridAdapter);
        $sparse = $compactor->applyViewportUpdate(
            $element->getGridSettingsData(),
            $body->viewport,
            ['width' => $body->width, 'offset' => $body->offset, 'visible' => $body->visible],
        );

        $element->setGridSettingsData($sparse);

        $result = $this->persistenceService->persistBatch([$element]);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($element);

        return $this->jsonSuccess(204);
    }

    public function apiAcceptableContainers(HTTPRequest $request): HTTPResponse
    {
        $pageId = (int) $request->param('PageID');
        $elementType = (string) $request->param('ElementType');

        // Map element type to the container type that holds it
        $targetContainerType = match ($elementType) {
            'row' => ContainerType::Section,
            'column' => ContainerType::Row,
            'element' => ContainerType::Column,
            'section' => null,
            default => null,
        };

        if ($elementType !== 'section' && $targetContainerType === null) {
            $this->jsonError(400);
        }

        // Sections are root-level — no container needed
        if ($elementType === 'section') {
            return $this->jsonSuccess(200, []);
        }

        /** @var SiteTree|null $page */
        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        /** @var non-empty-string $zone Route pattern guarantees non-empty zone segment */
        $zone = (string) $request->param('Zone');
        $tree = $this->treeBuilder->buildForPage($page, $zone);

        $rootNodes = $tree[(int) $page->ID] ?? [];

        /** @var list<array{id: positive-int, title: string, type: string}> $containers */
        $containers = [];
        assert($targetContainerType instanceof ContainerType);
        $this->collectContainersOfType($rootNodes, $targetContainerType, $containers);

        return $this->jsonSuccess(200, $containers);
    }

    public function apiZones(HTTPRequest $request): HTTPResponse
    {
        $pageId = (int) $request->param('PageID');

        /** @var SiteTree|null $page */
        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $fields = $page->getCMSFields();

        /** @var list<non-empty-string> $zones */
        $zones = [];
        foreach ($fields->flattenFields() as $field) {
            if ($field instanceof GridEditorField) {
                $zones[] = $field->getZone();
            }
        }

        return $this->jsonSuccess(200, array_values(array_unique($zones)));
    }

    public function apiPages(HTTPRequest $request): HTTPResponse
    {
        $search = $request->getVar('search');

        /** @var list<array{id: positive-int, title: string, parentId: int, hasGridZones: bool}> $results */
        $results = Versioned::withVersionedMode(static function () use ($search): array {
            Versioned::set_stage(Versioned::DRAFT);

            $pages = SiteTree::get()->sort('Title', 'ASC');

            if (is_string($search) && $search !== '') {
                $pages = $pages->filter('Title:PartialMatch', $search);
            }

            $pages = $pages->limit(50);

            /** @var list<array{id: positive-int, title: string, parentId: int, hasGridZones: bool}> $items */
            $items = [];
            foreach ($pages as $page) {
                if (!$page->canEdit()) {
                    continue;
                }

                $hasGridZones = $page->hasExtension(GridPageExtension::class);

                /** @var positive-int $id */
                $id = (int) $page->ID;

                $items[] = [
                    'id' => $id,
                    'title' => (string) $page->Title,
                    'parentId' => (int) $page->ParentID,
                    'hasGridZones' => $hasGridZones,
                ];
            }

            return $items;
        });

        return $this->jsonSuccess(200, $results);
    }

    /**
     * Recursively collect containers matching the target type from the tree.
     *
     * @param list<GridNode> $nodes
     * @param list<array{id: positive-int, title: string, type: string}> $containers Collected results (by reference)
     */
    private function collectContainersOfType(array $nodes, ContainerType $targetType, array &$containers): void
    {
        foreach ($nodes as $node) {
            if ($node->containerType === $targetType) {
                $containers[] = [
                    'id' => $node->id,
                    'title' => $node->title,
                    'type' => $targetType->value,
                ];
            }

            if ($node->children !== null) {
                $this->collectContainersOfType($node->children, $targetType, $containers);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getClientConfig(): array
    {
        /** @var array<string, mixed> $clientConfig */
        $clientConfig = parent::getClientConfig();
        $clientConfig['controllerLink'] = $this->Link();
        $clientConfig['gridAdapter'] = self::buildAdapterConfig($this->gridAdapter);

        return $clientConfig;
    }

    /**
     * Build the grid adapter config for frontend consumption.
     *
     * Exposed as a static method so unit tests can verify the adapter config
     * shape without requiring the full SilverStripe framework bootstrap that
     * {@see getClientConfig()} depends on via its parent class.
     *
     * @return AdapterConfig
     */
    public static function buildAdapterConfig(GridAdapterInterface $adapter): array
    {
        $viewports = $adapter->getViewports();

        if ($viewports === []) {
            throw new InvalidArgumentException('Adapter must define at least one viewport.');
        }

        $columnCount = $adapter->getColumnCount();

        $widthClasses = [];
        for ($width = 1; $width <= $columnCount; ++$width) {
            $widthClasses[$width] = $adapter->getBaseWidthClass($width);
        }

        $offsetClasses = [];
        for ($offset = 0; $offset < $columnCount; ++$offset) {
            $offsetClasses[$offset] = $adapter->getBaseOffsetClass($offset);
        }

        /** @var AdapterConfig['baseWidthClasses'] $baseWidthClasses */
        $baseWidthClasses = (object) $widthClasses;

        /** @var AdapterConfig['baseOffsetClasses'] $baseOffsetClasses */
        $baseOffsetClasses = (object) $offsetClasses;

        return [
            'viewports' => array_map(
                static fn (Viewport $vp): array => [
                    'key' => $vp->key,
                    'label' => $vp->label,
                ],
                $viewports,
            ),
            'defaultViewport' => $adapter->getDefaultViewport()->key,
            'columnCount' => $columnCount,
            'rowClasses' => $adapter->getRowClasses(),
            'offsetStrategy' => $adapter->getOffsetStrategy()->value,
            'baseWidthClasses' => $baseWidthClasses,
            'baseOffsetClasses' => $baseOffsetClasses,
        ];
    }

    /**
     * Write the owning page to DRAFT so it appears as "modified" in the CMS.
     *
     * Accepts either a GridElement (walks parent chain) or a pre-resolved SiteTree
     * (for delete operations where the element is already archived).
     */
    private function touchOwningPage(GridElement|SiteTree $subject): void
    {
        $page = $subject instanceof SiteTree ? $subject : $subject->getPage();

        if (!$page instanceof SiteTree) {
            return;
        }

        // writeToStage calls forceChange() + write() within the correct
        // reading mode and creates a new draft version even when no page
        // fields have changed
        $page->writeToStage(Versioned::DRAFT);
    }

    /**
     * Decode the JSON request body into an associative array, or 400 on failure.
     *
     * @return array<string, mixed>
     */
    private function parseJsonBody(HTTPRequest $request): array
    {
        $data = json_decode($request->getBody() ?? '', true);

        if (!is_array($data)) {
            $this->jsonError(400);
        }

        /** @var array<string, mixed> $data JSON object keys are always strings */
        return $data;
    }

    /**
     * Parse and validate the element ID from the JSON request body.
     *
     * @return positive-int
     */
    private function requireElementIdFromRequest(HTTPRequest $request): int
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseElementId($data);
        if ($parseResult->isErr()) {
            $this->jsonError(400);
        }

        return $parseResult->unwrap();
    }

    /**
     * Load a grid element by ID, or 400/403 on failure.
     *
     * @param positive-int $id
     * @param callable(GridElement): bool $permissionCheck
     */
    private function requireElementWithPermission(int $id, callable $permissionCheck): GridElement
    {
        $element = $this->elementRepository->findById($id);
        if ($element === null) {
            $this->jsonError(400);
        }

        if (!$permissionCheck($element)) {
            $this->jsonError(403);
        }

        return $element;
    }

    /**
     * Resolve a parent record by ID, querying the correct table based on the
     * element's hierarchy level to avoid ID collisions between GridElement and SiteTree.
     *
     * Sections live under SiteTree pages; all other elements live under GridElements.
     */
    private function resolveParentRecord(int $parentId, GridElement $element): ?DataObject
    {
        return Versioned::withVersionedMode(static function () use ($parentId, $element): ?DataObject {
            Versioned::set_stage(Versioned::DRAFT);

            if (is_a($element->ParentClass, SiteTree::class, true)) {
                return SiteTree::get()->byID($parentId);
            }

            return GridElement::get()->byID($parentId);
        });
    }

    /**
     * Convert a failed Result into a JSON error response.
     *
     * @template T
     * @param Result<T> $result
     */
    private function resultToResponse(Result $result, int $statusCode = 422): never
    {
        $messages = array_map(
            static fn (ValidationError $error): string => $error->message,
            $result->errors(),
        );

        $this->jsonError($statusCode, implode(' ', $messages));
    }
}

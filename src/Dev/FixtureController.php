<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Dev;

use Override;
use InvalidArgumentException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Dev-only HTTP controller for loading and resetting E2E fixtures.
 *
 * Registered with DevelopmentAdmin at /dev/grid-fixtures
 * via _config/dev.yml (gated by Only: environment: dev).
 *
 * Playwright calls POST /load with a fixture name and gets back JSON
 * with page IDs for CMS navigation. POST /reset cleans up all E2E data.
 */
class FixtureController extends Controller
{
    /** @var array<string, string> */
    private static array $url_handlers = [
        'POST load' => 'load',
        'POST reset' => 'reset',
    ];

    /** @var list<string> */
    private static array $allowed_actions = [
        'load',
        'reset',
    ];

    /**
     * DevelopmentAdmin checks this before exposing the route.
     */
    public function canInit(): bool
    {
        return Director::isDev();
    }

    /**
     * Defense-in-depth: block non-dev access even if DevelopmentAdmin
     * config gating is somehow bypassed.
     */
    #[Override]
    protected function init(): void
    {
        parent::init();

        if (!Director::isDev()) {
            $this->httpError(404);
        }
    }

    public function load(HTTPRequest $request): HTTPResponse
    {
        $fixtureName = $request->postVar('fixture');
        if (!is_string($fixtureName) || $fixtureName === '') {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => 'Missing required "fixture" parameter',
            ]);
        }

        $loader = FixtureLoader::create();

        /** @var non-empty-string $fixtureName Narrowed by is_string + === '' guard above */
        try {
            $result = $loader->load($fixtureName);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => $invalidArgumentException->getMessage(),
            ]);
        }

        return $this->jsonResponse(200, [
            'success' => true,
            'fixture' => $result->fixtureName,
            'data' => $result,
        ]);
    }

    public function reset(HTTPRequest $request): HTTPResponse
    {
        // Require an explicit opt-in so an accidental curl/browser hit
        // on the dev endpoint cannot wipe fixture-loaded pages.
        if ($request->getVar('confirm') !== '1') {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => 'Missing confirm=1 query parameter',
            ]);
        }

        $loader = FixtureLoader::create();
        $loader->reset();

        return $this->jsonResponse(200, [
            'success' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $statusCode, array $data): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->setStatusCode($statusCode);
        $response->addHeader('Content-Type', 'application/json');
        $response->setBody(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }
}

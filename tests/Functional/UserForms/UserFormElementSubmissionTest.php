<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\UserForms;

use Override;
use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\UserForms\UserFormElement;
use WeDevelop\Grid\UserForms\SubmittedFormHostPageExtension;
use WeDevelop\Grid\UserForms\UserFormElementController;
use WeDevelop\Grid\UserForms\UserFormEmailDataExtension;
use WeDevelop\Grid\UserForms\UserFormRouteExtension;

/**
 * The visitor's path, anonymous, on LIVE. Writes happen on DRAFT and are
 * published; URLs are built on LIVE because a DRAFT Link() carries
 * ?stage=Stage, which an anonymous visitor may not read.
 */
#[CoversClass(UserFormRouteExtension::class)]
#[CoversClass(UserFormElementController::class)]
#[CoversClass(UserFormElement::class)]
#[CoversClass(SubmittedFormHostPageExtension::class)]
#[CoversClass(UserFormEmailDataExtension::class)]
final class UserFormElementSubmissionTest extends FunctionalTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        UserDefinedFormController::class => [CapturesRecipientEmails::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        CapturesRecipientEmails::$emails = [];

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testPageRendersTheFormPostingToTheGridFormRoute(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page));
        $page->publishRecursive();

        $body = $this->visit($page);

        self::assertStringContainsString(sprintf('action="%s"', $this->liveLink($page, 'grid-form', $form->ID, 'Form')), $body);
        self::assertStringContainsString('userforms/client/dist/js/userforms.js', $body, 'userforms assets load');
    }

    public function testSharedFormRendersForAnonymousVisitors(): void
    {
        $page = $this->page('test_page');
        [$block, $form] = $this->sharedForm();
        $block->publishRecursive();
        GridTreeFactory::reference($page, $block, sort: 1);
        $page->publishRecursive();

        $body = $this->visit($page);

        self::assertStringContainsString($this->liveLink($page, 'grid-form', $form->ID, 'Form'), $body);
        self::assertStringNotContainsString('Security/login', (string) $this->mainSession->lastUrl());
    }

    public function testValidSubmissionStoresTheHostPageAndShowsTheMessageInline(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page), 'Thanks for writing');
        $page->publishRecursive();

        $this->visit($page);
        $response = $this->post($this->liveLink($page, 'grid-form', $form->ID, 'Form'), $this->validData($form));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringEndsWith('/finished', $this->lastPath());
        self::assertStringContainsString('Thanks for writing', (string) $response->getBody());
        self::assertStringNotContainsString($this->liveLink($page, 'grid-form', $form->ID, 'Form'), (string) $response->getBody());

        $submission = SubmittedForm::get()->filter(['ParentID' => $form->ID, 'ParentClass' => UserFormElement::class])->first();
        self::assertInstanceOf(SubmittedForm::class, $submission);
        self::assertSame((int) $page->ID, (int) $submission->getField('HostPageID'));
    }

    public function testFinishedShowsOnlyTheSubmittedFormAsReceived(): void
    {
        $page = $this->page('test_page');
        $column = $this->columnIn($page);
        $submitted = $this->formIn($column, 'First form done');
        $other = $this->formIn($column, 'Second form done', sort: 2);
        $page->publishRecursive();

        $this->visit($page);
        $body = (string) $this->post($this->liveLink($page, 'grid-form', $submitted->ID, 'Form'), $this->validData($submitted))->getBody();

        self::assertStringContainsString('First form done', $body);
        self::assertStringNotContainsString('Second form done', $body);
        self::assertStringContainsString($this->liveLink($page, 'grid-form', $other->ID, 'Form'), $body);
    }

    public function testDirectVisitToFinishedBouncesToTheHostPage(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page), 'Thanks for writing');
        $page->publishRecursive();

        $body = (string) $this->get($this->liveLink($page, 'grid-form', $form->ID, 'finished'))->getBody();

        self::assertSame($this->liveLink($page), $this->lastPath());
        self::assertStringNotContainsString('Thanks for writing', $body);
        self::assertSame(0, SubmittedForm::get()->count());
    }

    public function testTheBaseRouteRedirectsToTheHostPage(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page));
        $page->publishRecursive();

        $this->get($this->liveLink($page, 'grid-form', $form->ID));

        self::assertSame($this->liveLink($page), $this->lastPath());
    }

    public function testRedirectPageReceivesTheVisitorAfterSubmitting(): void
    {
        $page = $this->page('test_page');
        $thanks = $this->page('test_page_2');
        $thanks->publishRecursive();
        $form = $this->formIn($this->columnIn($page), 'Inline message');
        $form->RedirectPageID = $thanks->ID;
        $form->write();
        $page->publishRecursive();

        $this->visit($page);
        $body = (string) $this->post($this->liveLink($page, 'grid-form', $form->ID, 'Form'), $this->validData($form))->getBody();

        self::assertSame($this->liveLink($thanks), $this->lastPath());
        self::assertStringNotContainsString('Inline message', $body);
        self::assertSame(1, SubmittedForm::get()->count());
    }

    public function testARedirectPageThatNoLongerExistsFallsBackToTheInlineMessage(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page), 'Inline message');
        $form->RedirectPageID = 999999;
        $form->write();
        $page->publishRecursive();

        $this->visit($page);
        $body = (string) $this->post($this->liveLink($page, 'grid-form', $form->ID, 'Form'), $this->validData($form))->getBody();

        self::assertStringContainsString('Inline message', $body);
    }

    public function testInvalidSubmissionReturnsToTheHostPageWithTheError(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page));
        $page->publishRecursive();

        $this->visit($page);
        $body = (string) $this->post(
            $this->liveLink($page, 'grid-form', $form->ID, 'Form'),
            [$this->nameField($form)->Name => '', 'action_process' => 'Submit'],
        )->getBody();

        self::assertSame($this->liveLink($page), $this->lastPath());
        self::assertStringContainsString('&#039;Name&#039; is required', $body);
        self::assertSame(0, SubmittedForm::get()->count());
    }

    public function testSharedFormRecordsEachHostPage(): void
    {
        $first = $this->page('test_page');
        $second = $this->page('test_page_2');
        [$block, $form] = $this->sharedForm();
        $block->publishRecursive();
        foreach ([$first, $second] as $page) {
            GridTreeFactory::reference($page, $block, sort: 1);
            $page->publishRecursive();
        }

        foreach ([$first, $second] as $page) {
            $this->visit($page);
            $this->post($this->liveLink($page, 'grid-form', $form->ID, 'Form'), $this->validData($form));
        }

        self::assertEqualsCanonicalizing(
            [(int) $first->ID, (int) $second->ID],
            array_map('intval', SubmittedForm::get()->filter('ParentID', $form->ID)->column('HostPageID')),
        );
    }

    #[DataProvider('saveSubmissionsProvider')]
    public function testRecipientEmailNamesTheHostPage(bool $disableSaveSubmissions): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page));
        $form->DisableSaveSubmissions = $disableSaveSubmissions;
        $form->write();
        $recipient = EmailRecipient::create();
        $recipient->EmailAddress = 'recipient@example.com';
        $recipient->EmailFrom = 'website@example.com';
        $recipient->EmailSubject = 'New submission';
        $form->EmailRecipients()->add($recipient);
        $page->publishRecursive();

        $this->visit($page);
        $this->post($this->liveLink($page, 'grid-form', $form->ID, 'Form'), $this->validData($form));

        self::assertCount(1, CapturesRecipientEmails::$emails);
        $submission = CapturesRecipientEmails::$emails[0]->getData()->SubmittedForm;
        self::assertInstanceOf(SubmittedForm::class, $submission);
        self::assertSame((int) $page->ID, (int) $submission->HostPage()->ID);
        self::assertSame($disableSaveSubmissions ? 0 : 1, SubmittedForm::get()->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function saveSubmissionsProvider(): iterable
    {
        yield 'saved' => [false];
        yield 'not saved' => [true];
    }

    #[DataProvider('unreachableIdProvider')]
    public function testTheRouteRejectsElementsThePageCannotServe(string $case): void
    {
        $page = $this->page('test_page');
        $id = match ($case) {
            'non-numeric' => 'abc',
            'zero' => '0',
            'form on another page' => (string) $this->formIn($this->columnIn($this->page('test_page_2')))->ID,
            'not a form' => (string) GridTreeFactory::contentElement($this->columnIn($page))->ID,
        };
        $page->publishRecursive();
        $this->page('test_page_2')->publishRecursive();

        $response = $this->get($this->liveLink($page, 'grid-form', $id, 'Form'));

        self::assertSame(404, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function unreachableIdProvider(): iterable
    {
        foreach (['non-numeric', 'zero', 'form on another page', 'not a form'] as $case) {
            yield $case => [$case];
        }
    }

    private function page(string $id): Page
    {
        return $this->objFromFixture(Page::class, $id);
    }

    private function columnIn(Page|SharedBlock $parent): Column
    {
        return GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($parent)));
    }

    /** @return array{SharedBlock, UserFormElement} */
    private function sharedForm(): array
    {
        $block = GridTreeFactory::sharedBlock('Contact');

        return [$block, $this->formIn($this->columnIn($block), 'Shared thanks')];
    }

    private function formIn(Column $column, string $onComplete = 'Thanks', int $sort = 1): UserFormElement
    {
        $form = UserFormElement::create();
        $form->Title = 'Contact';
        $form->Sort = $sort;
        $form->OnCompleteMessage = '<p>' . $onComplete . '</p>';
        $form->ParentID = $column->ID;
        $form->ParentClass = Column::class;
        $form->write();

        $field = EditableTextField::create();
        $field->Title = 'Name';
        $field->Required = true;
        $form->Fields()->add($field);

        return $form;
    }

    private function nameField(UserFormElement $form): EditableFormField
    {
        $field = $form->Fields()->filter('ClassName', EditableTextField::class)->first();
        self::assertInstanceOf(EditableFormField::class, $field);

        return $field;
    }

    /** @return array<string, string> */
    private function validData(UserFormElement $form): array
    {
        return [$this->nameField($form)->Name => 'A visitor', 'action_process' => 'Submit'];
    }

    private function visit(SiteTree $page): string
    {
        $response = $this->get($this->liveLink($page));
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** A browser follows the whole redirect chain; FunctionalTest follows one hop. */
    #[Override]
    public function get($url, $session = null, $headers = null, $cookies = null): HTTPResponse
    {
        return $this->followRedirects(parent::get($url, $session, $headers, $cookies));
    }

    #[Override]
    public function post($url, $data, $headers = null, $session = null, $body = null, $cookies = null): HTTPResponse
    {
        return $this->followRedirects(parent::post($url, $data, $headers, $session, $body, $cookies));
    }

    private function followRedirects(HTTPResponse $response): HTTPResponse
    {
        for ($hops = 0; $response->getHeader('Location') !== null; $hops++) {
            self::assertLessThan(5, $hops, 'Redirect loop');
            $response = $this->mainSession->followRedirection();
        }

        return $response;
    }

    /** TestSession keeps the last URL relative, without its leading slash. */
    private function lastPath(): string
    {
        return '/' . ltrim((string) parse_url((string) $this->mainSession->lastUrl(), PHP_URL_PATH), '/');
    }

    private function liveLink(SiteTree $page, string|int ...$segments): string
    {
        // Re-read on LIVE: Link() appends the reading mode the record was
        // fetched in (VersionedStateExtension), not the ambient stage.
        return Versioned::withVersionedMode(static function () use ($page, $segments): string {
            Versioned::set_stage(Versioned::LIVE);
            $live = SiteTree::get()->byID($page->ID);
            self::assertInstanceOf(SiteTree::class, $live, 'The page must be published before its live link is built');

            return (string) Controller::join_links($live->Link(), ...array_map('strval', $segments));
        });
    }
}

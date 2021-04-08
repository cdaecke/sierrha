<?php

namespace Plan2net\Sierrha\Tests\Error;

use Plan2net\Sierrha\Utility\Url;
use TYPO3\CMS\Core\Controller\ErrorPageController;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;

class UrlTest extends UnitTestCase
{

    const ERROR_PAGE_CONTROLLER_CONTENT = 'FALLBACK ERROR TEXT';
    const ERROR_PAGE_TITLE = '*** Error Title ***';
    const ERROR_PAGE_MESSAGE = '*** Detailed error description. ***';

    /**
     * System Under Test
     *
     * @var Url
     */
    protected $sut;

    protected function setUp(): void
    {
        $this->sut = new Url();
    }

    protected function createLanguageServiceStub(): LanguageService
    {
        $languageServiceStub = $this->createMock(LanguageService::class);
        $languageServiceStub->method('sL')
            ->willReturn(self::ERROR_PAGE_TITLE, self::ERROR_PAGE_MESSAGE);

        return $languageServiceStub;
    }

    protected function setupErrorPageControllerStub(): void
    {
        $errorPageControllerStub = $this->getMockBuilder(ErrorPageController::class)
                                        ->disableOriginalConstructor()
                                        ->getMock();
        $errorPageControllerStub->method('errorAction')
                                ->willReturn(self::ERROR_PAGE_CONTROLLER_CONTENT);
        GeneralUtility::addInstance(ErrorPageController::class, $errorPageControllerStub);
    }

    protected function setupRequestFactoryStub($response): void
    {
        $requestFactoryStub = $this->getMockBuilder(RequestFactory::class)
            ->getMock();
        $requestFactoryStub->method('request')
            ->willReturn($response);
        GeneralUtility::addInstance(RequestFactory::class, $requestFactoryStub);
    }

    protected function buildResponseBody(string $body)
    {
        $stream = @fopen('php://memory', 'r+');
        fputs($stream, $body);
        rewind($stream);

        return $stream;
    }

    /**
     * @test
     */
    public function httpErrorOnFetchingUrlIsHandledGracefully(): void
    {
        $this->setupRequestFactoryStub(new Response($this->buildResponseBody('SERVER ERROR TEXT'), 500)); // anything but 200
        $this->setupErrorPageControllerStub();

        $result = $this->sut->fetchWithFallback('http://foo.bar/', $this->createLanguageServiceStub(), '');
        $this->assertEquals(self::ERROR_PAGE_CONTROLLER_CONTENT, $result);
    }

    /**
     * @test
     */
    public function emptyContentOfFetchedUrlIsHandledGracefully(): void
    {
        $this->setupRequestFactoryStub(new Response()); // will return an empty string
        $this->setupErrorPageControllerStub();

        $result = $this->sut->fetchWithFallback('http://foo.bar/', $this->createLanguageServiceStub(), '');
        $this->assertEquals(self::ERROR_PAGE_CONTROLLER_CONTENT, $result);
    }

    /**
     * @test
     */
    public function unusableContentOfFetchedUrlIsHandledGracefully(): void
    {
        $this->setupRequestFactoryStub(new Response($this->buildResponseBody(' <h1> </h1> <!-- empty --> ')));
        $this->setupErrorPageControllerStub();

        $result = $this->sut->fetchWithFallback('http://foo.bar/', $this->createLanguageServiceStub(), '');
        $this->assertEquals(self::ERROR_PAGE_CONTROLLER_CONTENT, $result);
    }

    /**
     * @test
     */
    public function fallbackPageIsReturnedOnError(): void
    {
        $this->setupRequestFactoryStub(new Response()); // will return an empty string

        $result = $this->sut->fetchWithFallback('http://foo.bar/', $this->createLanguageServiceStub(), '');
        $this->assertStringContainsString('<title>' . self::ERROR_PAGE_TITLE . '</title>', $result);
        $this->assertStringContainsString(self::ERROR_PAGE_MESSAGE, $result);
    }

    /**
     * @test
     */
    public function usableContentOfFetchedUrlIsReturned(): void
    {
        $errorPageContent = 'CUSTOM ERROR PAGE TEXT';
        $this->setupRequestFactoryStub(new Response($this->buildResponseBody($errorPageContent)));

        $result = $this->sut->fetchWithFallback('http://foo.bar/', $this->createLanguageServiceStub(), '');
        $this->assertEquals($errorPageContent, $result);
    }
}

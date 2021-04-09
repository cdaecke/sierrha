<?php
declare(strict_types=1);

namespace Plan2net\Sierrha\Error;

/*
 * Copyright 2019-2021 plan2net GmbH
 * 
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use Plan2net\Sierrha\Utility\Url;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Controller\ErrorPageController;
use TYPO3\CMS\Core\Error\PageErrorHandler\PageErrorHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A foundation class for error handlers.
 */
abstract class BaseHandler implements PageErrorHandlerInterface
{
    const CACHE_IDENTIFIER = 'pages';
    const KEY_PREFIX = '';

    /**
     * @var int
     */
    protected $statusCode = 0;

    /**
     * @var array
     */
    protected $handlerConfiguration = [];

    /**
     * @var array
     */
    protected $extensionConfiguration = [];

    /**
     * @var string
     */
    protected $typo3Language = 'default';

	/**
	 * @param int $statusCode
	 * @param array $configuration
	 */
	public function __construct(int $statusCode, array $configuration)
    {
        $this->statusCode = $statusCode;
        $this->handlerConfiguration = $configuration;
        try {
            $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('sierrha');
        } catch (\Exception $e) {
            // @todo log configuration error
            $this->extensionConfiguration = [];
        }
	}

    /**
     * Fetches content of URL, returns fallback on error.
     *
     * @param string $url
     * @param int $pageUid
     * @return string
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    protected function fetchUrl(string $url, int $pageUid = 0): string
    {
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache(static::CACHE_IDENTIFIER);
        $cacheIdentifier = 'sierrha_' . static::KEY_PREFIX . '_' . md5($url);
        $cacheContent = $cache->get($cacheIdentifier);

        if ($cacheContent) {
            $content = (string)$cacheContent;
        } else {
            $urlUtility = GeneralUtility::makeInstance(Url::class);
            $content = $urlUtility->fetchWithFallback($url, $this->getLanguageService(), static::KEY_PREFIX);
            if ($content !== '') {
                // @todo allow for custom cache lifetime
                $cacheTags = ['sierrha'];
                if ($pageUid > 0) {
                    // cache tag "pageId_" ensures that cache is purged when content of 404 page changes
                    $cacheTags[] = 'pageId_' . $pageUid;
                }
                $cache->set($cacheIdentifier, $content, $cacheTags);
            }
        }

        return $content;
    }

    /**
     * @param string $message
     * @param \Throwable $e
     * @return string
     * @throws ImmediateResponseException
     */
    protected function handleInternalFailure(string $message, \Throwable $e): string
    {
        // @todo add logging
        $title = 'Page Not Found';
        $exitImmediately = false;
        if (($this->extensionConfiguration['debugMode'] ?? false)
            || GeneralUtility::cmpIP(GeneralUtility::getIndpEnv('REMOTE_ADDR'),
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'])) {
            $title .= ': ' . $message;
            $message = get_class($e) . ': ' . $e->getMessage();
            if ($e->getCode()) {
                $message .= ' [code: ' . $e->getCode() . ']';
            }
            $exitImmediately = true;
        }
        // @todo add detailed debug output
        $content = GeneralUtility::makeInstance(ErrorPageController::class)->errorAction(
            $title,
            $message,
            AbstractMessage::ERROR
        );
        if ($exitImmediately) {
            throw new ImmediateResponseException(new HtmlResponse($content, 500));
        }

        return $content;
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        static $languageService = null;

        if (!$languageService) {
            if (isset($GLOBALS['LANG'])) {
                $languageService = $GLOBALS['LANG'];
            } else {
                $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)
                    ->create($this->typo3Language);
            }
        }

        return $languageService;
    }
}

<?php
declare(strict_types=1);

namespace Plan2net\Sierrha\Utility;

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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Controller\ErrorPageController;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A utility for URL handling.
 */
class Url
{
    /**
     * Resolve TYPO3 style URL into real world URL, replace language markers for external URL.
     *
     * @param ServerRequestInterface $request
     * @param string $typoLinkUrl
     * @return array
     * @throws \TYPO3\CMS\Core\Routing\InvalidRouteArgumentsException
     */
    public function resolve(ServerRequestInterface $request, string $typoLinkUrl): array
    {
        $value = [
            'url' => '',
            'typo3Language' => 'default',
            'pageUid' => 0
        ];

        $linkService = GeneralUtility::makeInstance(LinkService::class);
        $urlParams = $linkService->resolve($typoLinkUrl);
        if ($urlParams['type'] !== 'page' && $urlParams['type'] !== 'url') {
            throw new \InvalidArgumentException('The error handler accepts only TYPO3 links of type "page" or "url"', 1547651754);
        }

        /* @var $language SiteLanguage */
        $language = $request->getAttribute('language');
        $value['typo3Language'] = $language->getTypo3Language();

        if ($urlParams['type'] === 'url') {
            $value['url'] = str_replace(
                ['###ISO_639-1###', '###IETF_BCP47###'],
                [$language->getTwoLetterIsoCode(), $language->getHreflang()],
                $urlParams['url']
            );

            return $value;
        }

        $value['pageUid'] = (int)$urlParams['pageuid'];

        /* @var $site Site */
        $site = $request->getAttribute('site', null);
        if (!$site instanceof Site) {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId((int)$urlParams['pageuid']);
        }

        $value['url'] = (string)$site->getRouter()->generateUri(
            (int)$urlParams['pageuid'],
            ['_language' => $request->getAttribute('language', null)]
        );

        return $value;
    }

    /**
     * Fetches content of URL, returns fallback on error
     *
     * @param string $url
     * @param LanguageService $languageService
     * @param string $labelPrefix
     * @return string
     */
    public function fetchWithFallback(string $url, LanguageService $languageService, string $labelPrefix): string
    {
        $content = $this->getContent($url);
        if ($content === '') {
            $content = GeneralUtility::makeInstance(ErrorPageController::class)->errorAction(
                $languageService->sL('LLL:EXT:sierrha/Resources/Private/Language/locallang.xlf:' . $labelPrefix . 'Title'),
                $languageService->sL('LLL:EXT:sierrha/Resources/Private/Language/locallang.xlf:' . $labelPrefix . 'Details')
            );
        }

        return $content;
    }

    /**
     * @param string $url
     * @return string
     */
    protected function getContent(string $url): string
    {
        $content = '';
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        try {
            $response = $requestFactory->request($url, 'GET', ['headers' => ['X-Sierrha' => 1]]);
            if ($response->getStatusCode() === 200) {
                $content = $response->getBody()->getContents();
                if (trim(strip_tags($content)) === '') {
                    // an empty message is considered an error
                    // @todo add error logging
                    $content = '';
                }
            } else {
                // @todo add error logging
            }
        } catch (\Exception $e) {
            // @todo add error logging
        }

        return $content;
    }
}

<?php

declare(strict_types=1);
namespace Sinso\AppRoutes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

class AppRoutesMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler = null): ResponseInterface
    {
        $router = GeneralUtility::makeInstance(Router::class);
        try {
            $parameters = $router->getUrlMatcher()->match($request->getUri()->getPath());
        } catch (MethodNotAllowedException|ResourceNotFoundException $e) {
            // app routes did not match. go on with regular TYPO3 stack.
            return $handler->handle($request);
        }
        $request = $request->withQueryParams(array_merge(
            $request->getQueryParams(),
            $parameters
        ));
        return $this->handleWithParameters($parameters, $request);
    }

    protected function handleWithParameters(array $parameters, ServerRequestInterface $request): ResponseInterface
    {
        /** @var SiteInterface $site */
        $site = $request->getAttribute('site');
        $language = $this->getLanguage($site, $request);
        $request = $request->withAttribute('language', $language);
        GeneralUtility::makeInstance(Context::class)->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));

        $GLOBALS['TYPO3_REQUEST'] = $request;

        if (empty($parameters['handler'])) {
            throw new \Exception('Route must return a handler parameter', 1604066046);
        }
        $handler = GeneralUtility::makeInstance($parameters['handler']);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \Exception('Route must return a handler parameter which implements ' . RequestHandlerInterface::class, 1604066102);
        }

        if ($parameters['requiresTsfe'] ?? false) {
            $this->bootFrontendController($site, $request);
        }

        return $handler->handle($request);
    }

    protected function bootFrontendController(SiteInterface $site, ServerRequestInterface $request): ResponseInterface
    {
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $pageArguments = $request->getAttribute('routing', null);
        if (!$pageArguments instanceof PageArguments) {
            // Page Arguments must be set in order to validate. This middleware only works if PageArguments
            // is available, and is usually combined with the Page Resolver middleware
            return GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                $request,
                'Page Arguments could not be resolved',
                ['code' => PageAccessFailureReasons::INVALID_PAGE_ARGUMENTS]
            );
        }
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            throw new \RuntimeException('The PSR-7 Request attribute "frontend.user" needs to be available as FrontendUserAuthentication object (as created by the FrontendUserAuthenticator middleware).', 1590740612);
        }

        $controller = GeneralUtility::makeInstance(
            TypoScriptFrontendController::class,
            GeneralUtility::makeInstance(Context::class),
            $site,
            $request->getAttribute('language', $site->getDefaultLanguage()),
            $pageArguments,
            $frontendUser
        );
        if ($pageArguments->getArguments()['no_cache'] ?? $request->getParsedBody()['no_cache'] ?? false) {
            $controller->set_no_cache('&no_cache=1 has been supplied, so caching is disabled! URL: "' . (string)$request->getUri() . '"');
        }
        // Usually only set by the PageArgumentValidator
        if ($request->getAttribute('noCache', false)) {
            $controller->no_cache = true;
        }

        $controller->determineId($request);

        $request = $request->withAttribute('frontend.controller', $controller);
        $GLOBALS['TSFE'] = $controller;
    }

    protected function getLanguage(SiteInterface $site, ServerRequestInterface $request): SiteLanguage
    {
        $languageUid = (int)($request->getQueryParams()['L'] ?? 0);
        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === $languageUid) {
                return $siteLanguage;
            }
        }
        return $site->getDefaultLanguage();
    }
}

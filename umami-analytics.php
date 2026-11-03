<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\HTTP\Response as HttpResponse;
use Grav\Common\Plugin;
use Grav\Common\Processors\Events\RequestHandlerEvent;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\UmamiAnalytics\Api\EmbedController;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Class UmamiAnalyticsPlugin
 * @package Grav\Plugin
 */
class UmamiAnalyticsPlugin extends Plugin {

	/**
	 * @var string
	 */
	protected string $scriptSrc;
	/** @var string */
	protected string $websiteId;
	/** @var string */
	protected string $disableAutoTrack;
	/** @var string */
	protected string $domains;
	/** @var bool */
	protected bool $doNotTrack;
	/** @var bool */
	protected bool $performance;
	/** @var bool */
	protected bool $proxyEnabled;
	/** @var bool */
	protected bool $recorderEnabled;
	/** @var string */
	protected string $scriptPath;
	/** @var string */
	protected string $apiEndpoint;
	/** @var int */
	protected int $cacheTtl;

	/**
	 * @return array
	 *
	 * The getSubscribedEvents() gives the core a list of events
	 *     that the plugin wants to listen to. The key of each
	 *     array section is the event that the plugin listens to
	 *     and the value (in the form of an array) contains the
	 *     callable (or function) as well as the priority. The
	 *     higher the number the higher the priority.
	 */
	public static function getSubscribedEvents(): array {
		return [
			'onPluginsInitialized' => ['onPluginsInitialized', 0],
			'onApiSidebarItems' => ['onApiSidebarItems', 0],
			'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
		];
	}

	/**
	 * Add an Umami analytics page to the Admin2 sidebar.
	 */
	public function onApiSidebarItems(Event $event): void {
		$items = $event['items'];
		$items[] = [
			'id' => 'umami-analytics',
			'plugin' => 'umami-analytics',
			'label' => 'Umami Analytics',
			'icon' => 'fa-chart-line',
			'route' => '/plugin/umami-analytics',
			'priority' => 40,
			'authorize' => 'api.access',
		];
		$event['items'] = $items;
	}

	/**
	 * Register the authenticated endpoint used by the Admin2 page.
	 */
	public function onApiRegisterRoutes(Event $event): void {
		$event['routes']->get('/umami-analytics/embed', [EmbedController::class, 'embed']);
	}

	/**
	 * Composer autoload
	 *
	 * @return ClassLoader
	 */
	public function autoload(): ClassLoader {
		return require __DIR__ . '/vendor/autoload.php';
	}

	/**
	 * Initialize the plugin
	 */
	public function onPluginsInitialized(): void {
		// Don't proceed if we are in the admin plugin
		if ($this->isAdmin()) {
			return;
		}

		// Don't proceed if we are in the localhost
		$uri = $this->grav['uri']->host();
		$enableLocalhost = $this->config->get('plugins.umami-analytics.enable_localhost', false);
		if (($uri === 'localhost' || $uri === '127.0.0.1') && !$enableLocalhost) {
			$this->grav['debugger']->addMessage("Umami Analytics Plugin: Ignoring localhost!");
			return;
		}

		// Load the plugin configuration
		$this->scriptSrc = rtrim(trim((string)$this->config->get('plugins.umami-analytics.script_src', 'https://us.umami.is')), '/');
		$this->websiteId = trim((string)$this->config->get('plugins.umami-analytics.website_id', ''));
		$this->disableAutoTrack = trim((string)$this->config->get('plugins.umami-analytics.disable_auto_track', ''));
		$this->domains = trim((string)$this->config->get('plugins.umami-analytics.domains', ''));
		$this->doNotTrack = filter_var($this->config->get('plugins.umami-analytics.doNotTrack', true), FILTER_VALIDATE_BOOLEAN);
		$this->performance = filter_var($this->config->get('plugins.umami-analytics.performance', false), FILTER_VALIDATE_BOOLEAN);
		$this->recorderEnabled = filter_var($this->config->get('plugins.umami-analytics.recorder', false), FILTER_VALIDATE_BOOLEAN);
		$this->proxyEnabled = filter_var($this->config->get('plugins.umami-analytics.proxy.enable', true), FILTER_VALIDATE_BOOLEAN);
		$this->scriptPath = trim((string)$this->config->get('plugins.umami-analytics.proxy.script_path', 'stats.js'), '/');
		$this->apiEndpoint = trim((string)$this->config->get('plugins.umami-analytics.proxy.api_endpoint', '/stats-api/'));
		$this->cacheTtl = max(0, (int)$this->config->get('plugins.umami-analytics.proxy.cache_ttl', 3600));

		$source = parse_url($this->scriptSrc);
		if (!$source || !in_array($source['scheme'] ?? '', ['http', 'https'], true) || empty($source['host'])) {
			$this->grav['debugger']->addMessage('Umami Analytics Plugin: The script source must be an HTTP(S) URL.', 'error');
			return;
		}

		if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.js$~', $this->scriptPath)) {
			$this->grav['debugger']->addMessage('Umami Analytics Plugin: The proxy script path must be a relative .js path using letters, numbers, underscores, and hyphens.', 'error');
			return;
		}

		if (!preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/?$~', $this->apiEndpoint)) {
			$this->grav['debugger']->addMessage('Umami Analytics Plugin: The API endpoint must be a root-relative path using letters, numbers, underscores, and hyphens.', 'error');
			return;
		}

		// Don't proceed if there is no website ID
		if (empty($this->websiteId)) {
			$this->grav['debugger']->addMessage('Umami Analytics Plugin: No website ID configured!', 'error');
			return;
		}

		$events = [
			'onAssetsInitialized' => ['onAssetsInitialized', 0],
		];
		if ($this->proxyEnabled) {
			$events['onRequestHandlerInit'] = ['onRequestHandlerInit', 0];
		}
		$this->enable($events);
	}

	/**
	 * Add the plugin assets
	 */
	public function onAssetsInitialized(): void {
		$uri = $this->grav['uri'];
		$requestUri = $this->grav['request']->getUri();
		$rootPath = rtrim($uri->rootUrl(), '/');
		$scriptUrl = $rootPath . '/' . $this->scriptPath;
		$apiBasePath = $rootPath . rtrim($this->apiEndpoint, '/');
		if (!$this->proxyEnabled) {
			$scriptUrl = $this->scriptSrc . '/script.js';
		}

		$options = [
			'loading' => 'defer',
			'data-website-id' => $this->websiteId,
			'data-auto-track' => $this->disableAutoTrack,
			'data-domains' => $this->domains,
			'data-do-not-track' => $this->doNotTrack ? 'true' : 'false',
			'data-performance' => $this->performance ? 'true' : 'false',
		];
		if ($this->proxyEnabled) {
			$options['data-host-url'] = $requestUri->getScheme() . '://' . $requestUri->getAuthority() . $apiBasePath;
		}

		$this->grav['assets']->addJs($scriptUrl, $options);

		if ($this->recorderEnabled) {
			$recorderPath = preg_replace('/\.js$/', '-recorder.js', $this->scriptPath);
			$recorderUrl = $this->proxyEnabled
				? $rootPath . '/' . $recorderPath
				: $this->scriptSrc . '/recorder.js';
			$recorderOptions = [
				'loading' => 'defer',
				'data-website-id' => $this->websiteId,
			];
			if ($this->proxyEnabled) {
				$recorderOptions['data-host-url'] = $requestUri->getScheme() . '://' . $requestUri->getAuthority() . $apiBasePath;
			}
			$this->grav['assets']->addJs($recorderUrl, $recorderOptions);
		}
	}

	/**
	 * Handle the local script and allowlisted Umami API proxy endpoints.
	 */
	public function onRequestHandlerInit(RequestHandlerEvent $event): void {
		$request = $event->getRequest();
		$rootPath = rtrim($this->grav['uri']->rootUrl(), '/');
		$scriptPath = $rootPath . '/' . $this->scriptPath;
		$apiBasePath = $rootPath . rtrim($this->apiEndpoint, '/');
		$path = $request->getUri()->getPath();
		$method = strtoupper($request->getMethod());

		if ($path === $scriptPath && in_array($method, ['GET', 'HEAD'], true)) {
			$event->setResponse($this->proxyScript($request, $apiBasePath, 'script.js'));
			return;
		}

		$recorderPath = $rootPath . '/' . preg_replace('/\.js$/', '-recorder.js', $this->scriptPath);
		if ($this->recorderEnabled && $path === $recorderPath && in_array($method, ['GET', 'HEAD'], true)) {
			$event->setResponse($this->proxyScript($request, $apiBasePath, 'recorder.js'));
			return;
		}

		$apiPath = substr($path, strlen($apiBasePath));
		if (!str_starts_with($path, $apiBasePath . '/') || !preg_match('~^/(?:send|batch|record|websites/[0-9a-fA-F-]+/recorder)$~', $apiPath)) {
			return;
		}

		if (!in_array($method, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
			$event->setResponse(new Response(405, ['Allow' => 'GET, HEAD, POST, OPTIONS'], 'Method Not Allowed'));
			return;
		}

		$event->setResponse($this->proxyApiRequest($request, $apiPath));
	}

	/**
	 * Fetch and cache Umami scripts, rewriting the upstream origin to this site's proxy URL.
	 */
	private function proxyScript(ServerRequestInterface $request, string $apiBasePath, string $scriptName): Response {
		$cacheKey = 'umami-analytics-script-' . md5($this->scriptSrc . '|' . $scriptName . '|' . $this->cacheTtl);
		$cached = $this->cacheTtl > 0 ? $this->grav['cache']->fetch($cacheKey) : false;
		if (is_array($cached) && isset($cached['body']) && is_string($cached['body'])) {
			$uri = $request->getUri();
			$localOrigin = $uri->getScheme() . '://' . $uri->getAuthority() . $apiBasePath;
			$body = $this->rewriteScript($cached['body'], $localOrigin);
			return new Response(200, [
				'Content-Type' => 'application/javascript',
				'Cache-Control' => 'public, max-age=' . $this->cacheTtl,
			], $request->getMethod() === 'HEAD' ? '' : $body);
		}

		try {
			$upstream = HttpResponse::request('GET', $this->scriptSrc . '/' . $scriptName, ['timeout' => 15]);
			$status = $upstream->getStatusCode();
			if ($status < 200 || $status >= 300) {
				return $this->createProxyResponse($upstream, $request->getMethod() === 'HEAD');
			}
		} catch (TransportExceptionInterface $exception) {
			$this->grav['debugger']->addMessage('Umami Analytics proxy request failed: ' . $exception->getMessage(), 'error');
			return new Response(502, ['Content-Type' => 'text/plain'], 'Unable to reach the Umami server.');
		}

		$upstreamBody = $upstream->getContent(false);
		if ($this->cacheTtl > 0) {
			$this->grav['cache']->save($cacheKey, ['body' => $upstreamBody], $this->cacheTtl);
		}
		$uri = $request->getUri();
		$localOrigin = $uri->getScheme() . '://' . $uri->getAuthority() . $apiBasePath;
		$body = $this->rewriteScript($upstreamBody, $localOrigin);

		return new Response(200, [
			'Content-Type' => 'application/javascript',
			'Cache-Control' => 'public, max-age=' . $this->cacheTtl,
		], $request->getMethod() === 'HEAD' ? '' : $body);
	}

	/**
	 * Rewrite upstream API URLs to use the configured same-origin proxy endpoint.
	 */
	private function rewriteScript(string $script, string $localOrigin): string {
		return str_replace(
			[$this->scriptSrc, '/api/'],
			[$localOrigin, '/'],
			$script
		);
	}

	/**
	 * Forward a request to one of the explicitly supported Umami API endpoints.
	 */
	private function proxyApiRequest(ServerRequestInterface $request, string $apiPath): Response {
		$headers = [];
		$blockedHeaders = [
			'connection', 'content-length', 'host', 'keep-alive', 'proxy-connection',
			'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
			'transfer-encoding', 'upgrade', 'x-forwarded-for',
		];
		foreach (explode(',', $request->getHeaderLine('Connection')) as $connectionHeader) {
			$connectionHeader = strtolower(trim($connectionHeader));
			if ($connectionHeader !== '') {
				$blockedHeaders[] = $connectionHeader;
			}
		}
		foreach ($request->getHeaders() as $name => $values) {
			if (!in_array(strtolower($name), $blockedHeaders, true)) {
				$headers[$name] = $values;
			}
		}

		$clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
		if ($clientIp !== '') {
			$headers['X-Forwarded-For'] = $clientIp;
		}

		$options = ['headers' => $headers, 'timeout' => 15];
		if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
			$body = $request->getBody();
			if ($body->isSeekable()) {
				$body->rewind();
			}
			$options['body'] = $body->getContents();
		}

		$query = $request->getUri()->getQuery();
		$upstreamUrl = $this->scriptSrc . '/api' . $apiPath . ($query !== '' ? '?' . $query : '');
		try {
			$upstream = HttpResponse::request($request->getMethod(), $upstreamUrl, $options);
		} catch (TransportExceptionInterface $exception) {
			$this->grav['debugger']->addMessage('Umami Analytics proxy request failed: ' . $exception->getMessage(), 'error');
			return new Response(502, ['Content-Type' => 'text/plain'], 'Unable to reach the Umami server.');
		}

		return $this->createProxyResponse($upstream, $request->getMethod() === 'HEAD');
	}

	/**
	 * Convert an upstream response while removing hop-by-hop and stale entity headers.
	 */
	private function createProxyResponse($upstream, bool $headRequest): Response {
		$headers = [];
		$blockedHeaders = [
			'connection', 'content-encoding', 'content-length', 'keep-alive',
			'proxy-authenticate', 'proxy-authorization', 'proxy-connection', 'te', 'trailer',
			'transfer-encoding', 'upgrade',
		];
		$upstreamHeaders = $upstream->getHeaders(false);
		foreach ($upstreamHeaders['connection'] ?? [] as $connectionHeader) {
			foreach (explode(',', $connectionHeader) as $headerName) {
				$headerName = strtolower(trim($headerName));
				if ($headerName !== '') {
					$blockedHeaders[] = $headerName;
				}
			}
		}
		foreach ($upstreamHeaders as $name => $values) {
			if (!in_array(strtolower($name), $blockedHeaders, true)) {
				$headers[$name] = $values;
			}
		}

		return new Response(
			$upstream->getStatusCode(),
			$headers,
			$headRequest ? '' : $upstream->getContent(false)
		);
	}
}

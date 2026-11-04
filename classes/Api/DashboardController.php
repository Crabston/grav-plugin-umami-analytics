<?php

declare(strict_types=1);

namespace Grav\Plugin\UmamiAnalytics\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class DashboardController extends AbstractApiController {
	public function dashboard(ServerRequestInterface $request): ResponseInterface {
		$this->requirePermission($request, 'api.access');
		$translate = fn(string $key): string => $this->grav['language']->translate('PLUGIN_UMAMI_ANALYTICS.API.' . $key);

		$websiteId = trim((string)$this->config->get('plugins.umami-analytics.website_id', ''));
		$token = trim((string)$this->config->get('plugins.umami-analytics.api_token', ''));
		if ($websiteId === '' || $token === '') {
			return ApiResponse::create(['configured' => false]);
		}
		if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $websiteId)) {
			throw new ValidationException($translate('INVALID_WEBSITE_ID'));
		}

		$query = $request->getQueryParams();
		$days = filter_var($query['days'] ?? 7, FILTER_VALIDATE_INT);
		if ($days === false || !in_array($days, [7, 30, 90], true)) {
			throw new ValidationException($translate('INVALID_RANGE'));
		}

		// Check cache first (60-second TTL to avoid hammering the Umami API on every request)
		$cacheTtl = (int)$this->config->get('plugins.umami-analytics.dashboard_cache_ttl', 60);
		$cacheHeaders = $cacheTtl > 0 ? ['Cache-Control' => "public, max-age={$cacheTtl}"] : [];
		if ($cacheTtl > 0) {
			$cacheKey = 'umami-analytics-dashboard-' . md5($websiteId . '|' . $days);
			$cached = $this->grav['cache']->fetch($cacheKey);
			if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
				return ApiResponse::create($cached['data'], 200, $cacheHeaders);
			}
		}

		$baseUrl = trim((string)$this->config->get('plugins.umami-analytics.api_base_url', ''));
		if ($baseUrl === '') {
			$baseUrl = rtrim(trim((string)$this->config->get('plugins.umami-analytics.script_src', '')), '/') . '/api';
		}
		$server = parse_url($baseUrl);
		if (
			!$server
			|| !in_array($server['scheme'] ?? '', ['http', 'https'], true)
			|| empty($server['host'])
			|| isset($server['query'])
			|| isset($server['fragment'])
			|| isset($server['user'])
			|| isset($server['pass'])
		) {
			throw new ValidationException($translate('INVALID_URL'));
		}

		$endAt = (int)(microtime(true) * 1000);
		$startAt = $endAt - ($days * 86400000);
		$range = ['startAt' => $startAt, 'endAt' => $endAt];
		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Accept' => 'application/json',
		];
		$options = ['headers' => $headers, 'timeout' => 20];

		// Build all request URLs with their query parameters
		$endpoint = '/websites/' . $websiteId;
		$urls = [
			'stats' => $baseUrl . $endpoint . '/stats',
			'pageviews' => $baseUrl . $endpoint . '/pageviews',
			'paths' => $baseUrl . $endpoint . '/metrics',
			'referrers' => $baseUrl . $endpoint . '/metrics',
			'countries' => $baseUrl . $endpoint . '/metrics',
			'browsers' => $baseUrl . $endpoint . '/metrics',
			'operatingSystems' => $baseUrl . $endpoint . '/metrics',
			'devices' => $baseUrl . $endpoint . '/metrics',
		];
		$queries = [
			'stats' => $range,
			'pageviews' => array_merge($range, ['unit' => 'day']),
			'paths' => array_merge($range, ['type' => 'path', 'limit' => 10]),
			'referrers' => array_merge($range, ['type' => 'referrer', 'limit' => 10]),
			'countries' => array_merge($range, ['type' => 'country', 'limit' => 10]),
			'browsers' => array_merge($range, ['type' => 'browser', 'limit' => 10]),
			'operatingSystems' => array_merge($range, ['type' => 'os', 'limit' => 10]),
			'devices' => array_merge($range, ['type' => 'device', 'limit' => 10]),
		];

		// Fire all requests concurrently using Symfony HttpClient
		$client = HttpClient::create($options);
		$responses = [];
		foreach ($urls as $key => $url) {
			$qs = http_build_query($queries[$key], '', '&', PHP_QUERY_RFC3986);
			$fullUrl = $qs !== '' ? $url . '?' . $qs : $url;
			$responses[$key] = $client->request('GET', $fullUrl);
		}

		// Collect all responses as they complete
		$results = [];
		try {
			foreach ($client->stream($responses) as $response => $chunk) {
				$key = array_search($response, $responses, true);
				if ($chunk->isLast()) {
					$status = $response->getStatusCode();
					$body = $response->getContent(false);
					if ($status < 200 || $status >= 300) {
						$this->grav['log']->error('Umami API returned status ' . $status . ' for ' . $key . ': ' . trim((string)$body));
						throw new ApiException(502, $translate('REQUEST_FAILED'), str_replace('%status%', (string)$status, $translate('UPSTREAM_ERROR')));
					}
					$data = json_decode($body, true);
					if (!is_array($data)) {
						$this->grav['log']->error('Umami API returned invalid JSON for ' . $key . ': ' . trim((string)$body));
						throw new ApiException(502, $translate('REQUEST_FAILED'), $translate('INVALID_RESPONSE'));
					}
					$results[$key] = $data;
				}
			}
		} catch (ApiException $e) {
			throw $e;
		} catch (TransportExceptionInterface $e) {
			$this->grav['log']->error('Umami API request failed: ' . $e->getMessage());
			throw new ApiException(502, $translate('REQUEST_FAILED'), $translate('CLIENT_ERROR'));
		} catch (\Exception $e) {
			$this->grav['log']->error('Umami API request failed: ' . $e->getMessage());
			throw new ApiException(502, $translate('REQUEST_FAILED'), $translate('CLIENT_ERROR'));
		}

		$rawStats = $results['stats'] ?? [];
		$pageviews = $results['pageviews'] ?? [];
		$paths = $results['paths'] ?? [];
		$referrers = $results['referrers'] ?? [];
		$countries = $results['countries'] ?? [];
		$browsers = $results['browsers'] ?? [];
		$operatingSystems = $results['operatingSystems'] ?? [];
		$devices = $results['devices'] ?? [];

		$row = $this->firstStatsRow($rawStats);
		$visits = (int)($row['visits'] ?? 0);
		$bounces = (int)($row['bounces'] ?? 0);
		$stats = [
			'pageviews' => (int)($row['pageviews'] ?? 0),
			'visitors' => (int)($row['visitors'] ?? 0),
			'visits' => $visits,
			'bounces' => $bounces,
			'bounceRate' => $visits ? round((min($bounces, $visits) / $visits) * 1000) / 10 : 0,
			'averageVisitDurationSeconds' => $visits ? round((int)($row['totaltime'] ?? 0) / $visits) : 0,
		];

		$resultData = [
			'stats' => $stats,
			'pageviews' => is_array($pageviews['pageviews'] ?? null) ? $pageviews['pageviews'] : [],
			'sessions' => is_array($pageviews['sessions'] ?? null) ? $pageviews['sessions'] : [],
			'metrics' => [
				'paths' => $paths ?? [],
				'referrers' => $referrers ?? [],
				'countries' => $countries ?? [],
				'browsers' => $browsers ?? [],
				'operatingSystems' => $operatingSystems ?? [],
				'devices' => $devices ?? [],
			],
		];

		// Cache the result for subsequent requests
		if ($cacheTtl > 0) {
			$cacheKey = 'umami-analytics-dashboard-' . md5($websiteId . '|' . $days);
			$this->grav['cache']->save($cacheKey, ['data' => $resultData], $cacheTtl);
		}

		return ApiResponse::create($resultData, 200, $cacheHeaders);
	}

	/**
	 * Extract the first stats row from the API response, mirroring the Node.js client's logic.
	 */
	private function firstStatsRow($value): array {
		if (is_array($value)) {
			if (isset($value[0])) {
				return $value[0] ?? [];
			}
			if (isset($value['pageviews'])) {
				return $value;
			}
			if (isset($value['0']) && is_array($value['0'])) {
				return $value['0'];
			}
		}
		return [];
	}
}

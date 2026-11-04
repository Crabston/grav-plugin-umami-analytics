<?php

declare(strict_types=1);

namespace Grav\Plugin\UmamiAnalytics\Api;

use Grav\Common\HTTP\Response as HttpResponse;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class DashboardController extends AbstractApiController {
	public function dashboard(ServerRequestInterface $request): ResponseInterface {
		$this->requirePermission($request, 'api.access');

		$websiteId = trim((string)$this->config->get('plugins.umami-analytics.website_id', ''));
		$token = trim((string)$this->config->get('plugins.umami-analytics.api_token', ''));
		if ($websiteId === '' || $token === '') {
			return ApiResponse::create(['configured' => false]);
		}
		if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $websiteId)) {
			throw new ValidationException('The Umami website ID is invalid.');
		}

		$query = $request->getQueryParams();
		$days = filter_var($query['days'] ?? 7, FILTER_VALIDATE_INT);
		if ($days === false || !in_array($days, [7, 30, 90], true)) {
			throw new ValidationException('The analytics range must be 7, 30, or 90 days.');
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
			throw new ValidationException('The Umami API URL must be a valid HTTP(S) URL without credentials or query parameters.');
		}

		$endAt = (int)(microtime(true) * 1000);
		$startAt = $endAt - ($days * 86400000);
		$range = ['startAt' => $startAt, 'endAt' => $endAt];
		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Accept' => 'application/json',
		];
		$options = ['headers' => $headers, 'timeout' => 20];

		try {
			$rawStats = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/stats', $range, $options);
			$pageviews = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/pageviews', array_merge($range, ['unit' => 'day']), $options);
			$paths = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/metrics', array_merge($range, ['type' => 'path', 'limit' => 10]), $options);
			$referrers = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/metrics', array_merge($range, ['type' => 'referrer', 'limit' => 10]), $options);
			$countries = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/metrics', array_merge($range, ['type' => 'country', 'limit' => 10]), $options);
			$browsers = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/metrics', array_merge($range, ['type' => 'browser', 'limit' => 10]), $options);
			$operatingSystems = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/metrics', array_merge($range, ['type' => 'os', 'limit' => 10]), $options);
			$devices = $this->apiGet($baseUrl . '/websites/' . $websiteId . '/metrics', array_merge($range, ['type' => 'device', 'limit' => 10]), $options);
		} catch (ApiException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->grav['log']->error('Umami API request failed: ' . $e->getMessage());
			throw new ApiException(502, 'Umami API request failed', 'The Umami API client could not load analytics. Check the API URL, token, website ID, and server logs.');
		}

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

		return ApiResponse::create([
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
		]);
	}

	/**
	 * Make a GET request to the Umami API and return the decoded JSON response.
	 */
	private function apiGet(string $url, array $query, array $options): array {
		$qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		$fullUrl = $qs !== '' ? $url . '?' . $qs : $url;
		try {
			$response = HttpResponse::request('GET', $fullUrl, $options);
		} catch (TransportExceptionInterface $e) {
			throw new ApiException(502, 'Umami API request failed', 'The Umami API client could not load analytics. Check the API URL, token, website ID, and server logs.');
		}
		$status = $response->getStatusCode();
		$body = $response->getContent(false);
		if ($status < 200 || $status >= 300) {
			$this->grav['log']->error('Umami API returned status ' . $status . ': ' . trim((string)$body));
			throw new ApiException(502, 'Umami API request failed', 'The Umami API returned an error (status ' . $status . '). Check the API URL, token, website ID, and server logs.');
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			$this->grav['log']->error('Umami API returned invalid JSON: ' . trim((string)$body));
			throw new ApiException(502, 'Umami API request failed', 'The Umami API returned an invalid response.');
		}
		return $data;
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

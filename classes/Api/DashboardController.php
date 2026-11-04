<?php

declare(strict_types=1);

namespace Grav\Plugin\UmamiAnalytics\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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

		$worker = dirname(__DIR__, 2) . '/node/fetch-dashboard.mjs';
		$dependency = dirname(__DIR__, 2) . '/node/node_modules/@umami/api-client/package.json';
		if (!function_exists('proc_open')) {
			throw new ApiException(503, 'Umami analytics unavailable', 'PHP proc_open is required to run the Umami API client.');
		}
		if (!is_file($worker) || !is_file($dependency)) {
			throw new ApiException(503, 'Umami analytics unavailable', 'Install the plugin Node dependencies by running npm ci in its node directory.');
		}

		$input = json_encode([
			'baseUrl' => rtrim($baseUrl, '/'),
			'token' => $token,
			'websiteId' => $websiteId,
			'startAt' => (int)(microtime(true) * 1000) - ($days * 86400000),
			'endAt' => (int)(microtime(true) * 1000),
		], JSON_UNESCAPED_SLASHES);
		if ($input === false) {
			throw new ApiException(500, 'Umami analytics failed', 'Could not prepare the Umami API request.');
		}

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open(['node', $worker], $descriptors, $pipes, dirname($worker));
		if (!is_resource($process)) {
			throw new ApiException(503, 'Umami analytics unavailable', 'Unable to start Node.js. Install Node.js and ensure it is available in the PHP process PATH.');
		}

		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$status = proc_close($process);

		if ($status !== 0 || !is_string($output) || $output === '') {
			$this->grav['log']->error('Umami API client failed: ' . trim((string)$error));
			throw new ApiException(502, 'Umami API request failed', 'The Umami API client could not load analytics. Check the API URL, token, website ID, and server logs.');
		}

		$data = json_decode($output, true);
		if (!is_array($data)) {
			$this->grav['log']->error('Umami API client returned invalid JSON.');
			throw new ApiException(502, 'Umami API request failed', 'The Umami API client returned an invalid response.');
		}

		return ApiResponse::create($data);
	}
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\UmamiAnalytics\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class EmbedController extends AbstractApiController {
	public function embed(ServerRequestInterface $request): ResponseInterface {
		$this->requirePermission($request, 'api.access');

		$shareId = trim((string)$this->config->get('plugins.umami-analytics.share_id', ''));
		if ($shareId === '') {
			return ApiResponse::create(['configured' => false]);
		}
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $shareId)) {
			throw new ValidationException('The Umami share ID contains invalid characters.');
		}

		$serverUrl = trim((string)$this->config->get('plugins.umami-analytics.script_src', ''));
		$server = parse_url($serverUrl);
		if (
			!$server
			|| !in_array($server['scheme'] ?? '', ['http', 'https'], true)
			|| empty($server['host'])
			|| isset($server['query'])
			|| isset($server['fragment'])
			|| isset($server['user'])
			|| isset($server['pass'])
		) {
			throw new ApiException(500, 'Invalid Umami configuration', 'The Umami server URL is invalid.');
		}

		return ApiResponse::create([
			'configured' => true,
			'share_url' => rtrim($serverUrl, '/') . '/share/' . rawurlencode($shareId),
		]);
	}
}

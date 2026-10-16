<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\Exception\GoogleApiException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

class GoogleClient {
	public const CALENDAR = 'https://www.googleapis.com/calendar/v3/';
	public const PEOPLE = 'https://people.googleapis.com/v1/';
	public const TASKS = 'https://tasks.googleapis.com/tasks/v1/';

	public function __construct(
		private IClientService $clientService,
		private TokenService $tokens,
	) {
	}

	/**
	 * @param array<string, scalar> $query
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 */
	public function request(SyncContext $ctx, string $method, string $url, array $query = [], ?array $body = null): array {
		if ($ctx->legacy && $method !== 'GET') {
			// The legacy token is read-only, and a preview must not write anyway
			throw new \LogicException('미리보기에서는 구글에 쓰지 않습니다.');
		}
		if ($query !== []) {
			$url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		}
		$options = [
			'headers' => ['Accept' => 'application/json'],
			'timeout' => 60,
			'http_errors' => false,
		];
		if ($body !== null) {
			$options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$options['headers']['Content-Type'] = 'application/json; charset=utf-8';
		}

		$refreshed = false;
		$retried = false;
		while (true) {
			$options['headers']['Authorization'] = 'Bearer ' . $this->tokens->getAccessToken($ctx->userId, $ctx->legacy, $refreshed);
			$ctx->requests++;
			$response = $this->clientService->newClient()->request($method, $url, $options);
			$status = $response->getStatusCode();
			if ($status === 401 && !$refreshed) {
				$refreshed = true;
				continue;
			}
			if ($status >= 500 && !$retried) {
				$retried = true;
				sleep(1);
				continue;
			}
			break;
		}
		return $this->decode($response, $status);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode(IResponse $response, int $status): array {
		$raw = (string)$response->getBody();
		$data = $raw === '' ? [] : json_decode($raw, true);
		if (!is_array($data)) {
			$data = [];
		}
		if ($status >= 400) {
			$error = is_array($data['error'] ?? null) ? $data['error'] : [];
			$reason = (string)($error['errors'][0]['reason'] ?? $error['status'] ?? '');
			foreach (($error['details'] ?? []) as $detail) {
				if (is_array($detail) && isset($detail['reason'])) {
					$reason .= ' ' . $detail['reason'];
				}
			}
			$message = (string)($error['message'] ?? ('HTTP ' . $status));
			throw new GoogleApiException('구글 API 오류 ' . $status . ': ' . $message, $status, trim($reason));
		}
		return $data;
	}

	/**
	 * @return array{string, string} body and content type
	 */
	public function download(string $url): array {
		$response = $this->clientService->newClient()->get($url, ['timeout' => 30, 'http_errors' => false]);
		if ($response->getStatusCode() >= 400) {
			throw new GoogleApiException('사진을 받지 못했습니다', $response->getStatusCode());
		}
		$type = $response->getHeader('Content-Type');
		return [(string)$response->getBody(), $type];
	}
}

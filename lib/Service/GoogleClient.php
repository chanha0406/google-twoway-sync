<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

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
	 * GET every page of a list endpoint.
	 *
	 * @param array<string, scalar|list<scalar>> $query
	 * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} the
	 *   items under $itemsKey and the last page (for nextSyncToken)
	 */
	public function listAll(SyncContext $ctx, string $url, array $query, string $itemsKey): array {
		$items = [];
		do {
			$result = $this->request($ctx, 'GET', $url, $query);
			foreach (($result[$itemsKey] ?? []) as $item) {
				if (is_array($item)) {
					$items[] = $item;
				}
			}
			$query['pageToken'] = (string)($result['nextPageToken'] ?? '');
		} while ($query['pageToken'] !== '');
		return [$items, $result];
	}

	/**
	 * @param array<string, scalar|list<scalar>> $query
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 */
	public function request(SyncContext $ctx, string $method, string $url, array $query = [], ?array $body = null): array {
		if ($ctx->legacy && $method !== 'GET') {
			// The legacy token is read-only, and a preview must not write anyway
			throw new \LogicException(T::t('Preview mode does not write to Google.'));
		}
		if ($query !== []) {
			$url .= (str_contains($url, '?') ? '&' : '?') . self::buildQuery($query);
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
		$rateTries = 0;
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
			// Critical reads are 90 per minute per user. Wait out a short window
			// once; a longer Retry-After is left to the next cron run.
			if ($status === 429 && $rateTries < 1) {
				$wait = self::retryAfter($response);
				if ($wait <= 65) {
					$rateTries++;
					sleep(max(1, $wait));
					continue;
				}
			}
			break;
		}
		return $this->decode($response, $status);
	}

	/**
	 * Repeated keys stay repeated (`resourceNames=a&resourceNames=b`), which
	 * people:batchGet requires. Scalars keep http_build_query encoding.
	 *
	 * @param array<string, scalar|list<scalar>> $query
	 */
	private static function buildQuery(array $query): string {
		$scalars = [];
		$parts = [];
		foreach ($query as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $item) {
					$parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$item);
				}
			} else {
				$scalars[$key] = $value;
			}
		}
		$encoded = $scalars === [] ? '' : http_build_query($scalars, '', '&', PHP_QUERY_RFC3986);
		if ($parts !== []) {
			$encoded .= ($encoded !== '' ? '&' : '') . implode('&', $parts);
		}
		return $encoded;
	}

	private static function retryAfter(IResponse $response): int {
		$header = $response->getHeader('Retry-After');
		if (is_array($header)) {
			$header = $header[0] ?? '';
		}
		$header = trim((string)$header);
		if ($header !== '' && ctype_digit($header)) {
			return (int)$header;
		}
		if ($header !== '') {
			$when = strtotime($header);
			if ($when !== false) {
				return max(0, $when - time());
			}
		}
		return 60;
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
			throw new GoogleApiException(T::t('Google API error %1$s: %2$s', [$status, $message]), $status, trim($reason));
		}
		return $data;
	}

	/**
	 * @return array{string, string} body and content type
	 */
	public function download(string $url): array {
		$response = $this->clientService->newClient()->get($url, ['timeout' => 30, 'http_errors' => false]);
		if ($response->getStatusCode() >= 400) {
			throw new GoogleApiException(T::t('Could not download the photo'), $response->getStatusCode());
		}
		$type = $response->getHeader('Content-Type');
		return [(string)$response->getBody(), $type];
	}
}

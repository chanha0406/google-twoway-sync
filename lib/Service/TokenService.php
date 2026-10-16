<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\AppInfo\Application;
use OCA\GoogleTwoWaySync\Exception\AuthException;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * OAuth tokens with write access to Calendar and People.
 *
 * The OAuth client is the one configured for Google Synchronization unless
 * this app has its own client_id / client_secret in its app config. Google
 * Synchronization only holds read-only tokens, which are used for previews
 * when this app is not connected yet.
 */
class TokenService {
	private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/** @var array<string, array{token: string, expires: int}> */
	private array $cache = [];

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
	) {
	}

	/**
	 * @return array{string, string}
	 */
	private function clientCredentials(): array {
		$id = $this->appConfig->getValueString(Application::APP_ID, 'client_id', '', lazy: true);
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret', '', lazy: true);
		if ($id === '' || $secret === '') {
			$id = $this->appConfig->getValueString(Application::LEGACY_APP_ID, 'client_id', '', lazy: true);
			$secret = $this->appConfig->getValueString(Application::LEGACY_APP_ID, 'client_secret', '', lazy: true);
		}
		if ($id === '' || $secret === '') {
			throw new AuthException('구글 OAuth 클라이언트가 설정되어 있지 않습니다. Google Synchronization 관리자 설정을 확인하세요.');
		}
		return [$id, $secret];
	}

	public function getRedirectUri(): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.config.oauthRedirect');
	}

	public function buildAuthUrl(string $userId): string {
		[$clientId] = $this->clientCredentials();
		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->userConfig->setValueString($userId, Application::APP_ID, 'oauth_state', $state, lazy: true);
		return self::AUTH_URL . '?' . http_build_query([
			'client_id' => $clientId,
			'redirect_uri' => $this->getRedirectUri(),
			'response_type' => 'code',
			'scope' => implode(' ', Application::SCOPES),
			'access_type' => 'offline',
			'prompt' => 'consent',
			'include_granted_scopes' => 'false',
			'state' => $state,
		], '', '&', PHP_QUERY_RFC3986);
	}

	public function finishAuth(string $userId, string $code, string $state): void {
		$expected = $this->userConfig->getValueString($userId, Application::APP_ID, 'oauth_state', '', lazy: true);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'oauth_state');
		if ($expected === '' || !hash_equals($expected, $state)) {
			throw new AuthException('OAuth 상태 값이 맞지 않습니다. 설정 화면에서 다시 연결하세요.');
		}
		[$clientId, $secret] = $this->clientCredentials();
		$result = $this->tokenRequest([
			'client_id' => $clientId,
			'client_secret' => $secret,
			'code' => $code,
			'redirect_uri' => $this->getRedirectUri(),
			'grant_type' => 'authorization_code',
		]);
		if (($result['refresh_token'] ?? '') === '') {
			throw new AuthException('구글이 refresh token을 주지 않았습니다. 구글 계정 권한에서 이 앱을 지우고 다시 연결하세요.');
		}
		$granted = explode(' ', (string)($result['scope'] ?? ''));
		$missing = array_diff(Application::SCOPES, $granted);
		if ($missing !== []) {
			throw new AuthException('캘린더·연락처·할 일 쓰기 권한을 모두 허용해야 합니다. 빠진 권한: ' . implode(', ', $missing));
		}
		$this->setSecret($userId, 'refresh_token', (string)$result['refresh_token']);
		$this->storeAccessToken($userId, $result);
		$this->userConfig->setValueString($userId, Application::APP_ID, 'scopes', implode(' ', $granted), lazy: true);
	}

	public function isConnected(string $userId): bool {
		return $this->userConfig->getValueString($userId, Application::APP_ID, 'refresh_token', '', lazy: true) !== '';
	}

	public function hasTasksScope(string $userId): bool {
		$scopes = preg_split('/\s+/', trim($this->userConfig->getValueString($userId, Application::APP_ID, 'scopes', '', lazy: true))) ?: [];
		return in_array('https://www.googleapis.com/auth/tasks', $scopes, true);
	}

	public function hasLegacyToken(string $userId): bool {
		return $this->userConfig->getValueString($userId, Application::LEGACY_APP_ID, 'refresh_token', '', lazy: true) !== '';
	}

	public function disconnect(string $userId): void {
		foreach (['refresh_token', 'access_token', 'token_expires_at', 'scopes', 'oauth_state'] as $key) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
		}
		unset($this->cache[$userId . '|0']);
	}

	/**
	 * @param bool $legacy use the read-only token of Google Synchronization
	 */
	public function getAccessToken(string $userId, bool $legacy, bool $forceRefresh = false): string {
		$cacheKey = $userId . '|' . ($legacy ? '1' : '0');
		$now = time();
		if (!$forceRefresh && isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expires'] > $now + 60) {
			return $this->cache[$cacheKey]['token'];
		}
		if ($legacy) {
			return $this->legacyAccessToken($userId, $cacheKey, $forceRefresh);
		}
		if (!$this->isConnected($userId)) {
			throw new AuthException('구글 계정이 연결되어 있지 않습니다.');
		}
		$expires = $this->userConfig->getValueInt($userId, Application::APP_ID, 'token_expires_at', 0, lazy: true);
		if (!$forceRefresh && $expires > $now + 60) {
			$token = $this->getSecret($userId, 'access_token');
			if ($token !== '') {
				$this->cache[$cacheKey] = ['token' => $token, 'expires' => $expires];
				return $token;
			}
		}
		[$clientId, $secret] = $this->clientCredentials();
		$result = $this->tokenRequest([
			'client_id' => $clientId,
			'client_secret' => $secret,
			'refresh_token' => $this->getSecret($userId, 'refresh_token'),
			'grant_type' => 'refresh_token',
		]);
		$token = $this->storeAccessToken($userId, $result);
		$this->cache[$cacheKey] = ['token' => $token, 'expires' => $now + (int)($result['expires_in'] ?? 3600)];
		return $token;
	}

	/**
	 * Refreshed in memory only, Google Synchronization keeps managing its token.
	 */
	private function legacyAccessToken(string $userId, string $cacheKey, bool $forceRefresh): string {
		$app = Application::LEGACY_APP_ID;
		$now = time();
		try {
			$expires = $this->userConfig->getValueInt($userId, $app, 'token_expires_at', 0, lazy: true);
		} catch (\Throwable) {
			$expires = 0;
		}
		if (!$forceRefresh && $expires > $now + 60) {
			$stored = $this->userConfig->getValueString($userId, $app, 'token', '', lazy: true);
			if ($stored !== '') {
				$token = $this->crypto->decrypt($stored);
				$this->cache[$cacheKey] = ['token' => $token, 'expires' => $expires];
				return $token;
			}
		}
		$refresh = $this->userConfig->getValueString($userId, $app, 'refresh_token', '', lazy: true);
		if ($refresh === '') {
			throw new AuthException('Google Synchronization에 저장된 구글 토큰이 없습니다.');
		}
		$id = $this->appConfig->getValueString($app, 'client_id', '', lazy: true);
		$secret = $this->appConfig->getValueString($app, 'client_secret', '', lazy: true);
		$result = $this->tokenRequest([
			'client_id' => $id,
			'client_secret' => $secret,
			'refresh_token' => $this->crypto->decrypt($refresh),
			'grant_type' => 'refresh_token',
		]);
		$token = (string)($result['access_token'] ?? '');
		$this->cache[$cacheKey] = ['token' => $token, 'expires' => $now + (int)($result['expires_in'] ?? 3600)];
		return $token;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function storeAccessToken(string $userId, array $result): string {
		$token = (string)($result['access_token'] ?? '');
		if ($token === '') {
			throw new AuthException('구글에서 액세스 토큰을 받지 못했습니다.');
		}
		$this->setSecret($userId, 'access_token', $token);
		$this->userConfig->setValueInt($userId, Application::APP_ID, 'token_expires_at', time() + (int)($result['expires_in'] ?? 3600), lazy: true);
		return $token;
	}

	/**
	 * @param array<string, string> $form
	 * @return array<string, mixed>
	 */
	private function tokenRequest(array $form): array {
		$response = $this->clientService->newClient()->post(self::TOKEN_URL, [
			'form_params' => $form,
			'timeout' => 30,
			'http_errors' => false,
		]);
		$body = json_decode((string)$response->getBody(), true);
		if (!is_array($body)) {
			$body = [];
		}
		if ($response->getStatusCode() >= 400 || isset($body['error'])) {
			$error = (string)($body['error'] ?? $response->getStatusCode());
			if ($error === 'invalid_grant') {
				throw new AuthException('구글 인증이 만료되었거나 취소되었습니다. 설정에서 다시 연결하세요.');
			}
			throw new AuthException('구글 토큰 요청 실패: ' . $error . ' ' . (string)($body['error_description'] ?? ''));
		}
		return $body;
	}

	private function setSecret(string $userId, string $key, string $value): void {
		$this->userConfig->setValueString(
			$userId,
			Application::APP_ID,
			$key,
			$this->crypto->encrypt($value),
			lazy: true,
			flags: IUserConfig::FLAG_SENSITIVE,
		);
	}

	private function getSecret(string $userId, string $key): string {
		$stored = $this->userConfig->getValueString($userId, Application::APP_ID, $key, '', lazy: true);
		return $stored === '' ? '' : $this->crypto->decrypt($stored);
	}
}

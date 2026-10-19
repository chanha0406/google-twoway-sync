<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Controller;

use OCA\GoogleTwoWaySync\L10n\T;

use OCA\GoogleTwoWaySync\AppInfo\Application;
use OCA\GoogleTwoWaySync\Exception\AuthException;
use OCA\GoogleTwoWaySync\Service\SyncService;
use OCA\GoogleTwoWaySync\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private TokenService $tokens,
		private SyncService $sync,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function oauthRedirect(string $code = '', string $state = '', string $error = ''): RedirectResponse {
		$target = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID]);
		if ($this->userId === null) {
			return new RedirectResponse($target);
		}
		if ($error !== '' || $code === '') {
			return new RedirectResponse($target . '?gtsyncError=' . rawurlencode($error !== '' ? $error : 'no_code'));
		}
		try {
			$this->tokens->finishAuth($this->userId, $code, $state);
			$this->sync->refreshCollections($this->userId);
		} catch (\Throwable $e) {
			$this->logger->warning('Google OAuth failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new RedirectResponse($target . '?gtsyncError=' . rawurlencode($e->getMessage()));
		}
		return new RedirectResponse($target . '?gtsyncConnected=1');
	}

	#[NoAdminRequired]
	public function connect(): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse(['url' => $this->tokens->buildAuthUrl($this->userId)]);
		} catch (AuthException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function disconnect(): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		$this->tokens->disconnect($this->userId);
		return new JSONResponse($this->sync->state($this->userId));
	}

	#[NoAdminRequired]
	public function state(): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		return new JSONResponse($this->sync->state($this->userId));
	}

	#[NoAdminRequired]
	public function refreshCollections(): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse($this->sync->refreshCollections($this->userId));
		} catch (AuthException $e) {
			return $this->error($e->getMessage(), Http::STATUS_UNAUTHORIZED);
		} catch (\Throwable $e) {
			return $this->loggedError($e);
		}
	}

	#[NoAdminRequired]
	public function matchGoogle(int $id): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		$name = filter_var($this->request->getParam('name', false), FILTER_VALIDATE_BOOLEAN);
		$color = filter_var($this->request->getParam('color', false), FILTER_VALIDATE_BOOLEAN);
		try {
			return new JSONResponse($this->sync->matchGoogleAppearance($this->userId, $id, $name, $color));
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->loggedError($e);
		}
	}

	#[NoAdminRequired]
	public function saveSelection(): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		$ids = $this->request->getParam('enabled');
		if (!is_array($ids)) {
			return $this->error(T::t('There is no list.'), Http::STATUS_BAD_REQUEST);
		}
		$this->sync->saveSelection($this->userId, array_values(array_map('intval', $ids)));
		$state = $this->sync->state($this->userId);
		$state['message'] = T::t('Saved.');
		return new JSONResponse($state);
	}

	#[NoAdminRequired]
	public function sync(bool $preview = false): JSONResponse {
		if ($this->userId === null) {
			return $this->error(T::t('You need to be logged in.'), Http::STATUS_UNAUTHORIZED);
		}
		$legacy = $preview && !$this->tokens->isConnected($this->userId) && $this->tokens->hasLegacyToken($this->userId);
		try {
			$result = $this->sync->syncUser($this->userId, $preview, $legacy);
		} catch (LockedException) {
			return $this->error(T::t('A sync is already running.'), Http::STATUS_CONFLICT);
		} catch (AuthException $e) {
			return $this->error($e->getMessage(), Http::STATUS_UNAUTHORIZED);
		} catch (\Throwable $e) {
			return $this->loggedError($e);
		}
		$result['state'] = $this->sync->state($this->userId);
		return new JSONResponse($result);
	}

	private function loggedError(\Throwable $e): JSONResponse {
		$this->logger->warning($e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		return $this->error($e->getMessage() !== '' ? $e->getMessage() : T::t('Could not connect to Google.'), Http::STATUS_BAD_GATEWAY);
	}

	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}
}

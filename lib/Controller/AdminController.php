<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Controller;

use OCA\GoogleTwoWaySync\L10n\T;

use OCA\GoogleTwoWaySync\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AdminController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private TokenService $tokens,
	) {
		parent::__construct($appName, $request);
	}

	#[PasswordConfirmationRequired]
	public function save(string $clientId = '', string $clientSecret = ''): JSONResponse {
		try {
			$this->tokens->saveAdminCredentials($clientId, $clientSecret);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$state = $this->tokens->adminCredentialState();
		$state['message'] = T::t('Saved.');
		return new JSONResponse($state);
	}
}

<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#connect', 'url' => '/connect', 'verb' => 'POST'],
		['name' => 'config#disconnect', 'url' => '/connect', 'verb' => 'DELETE'],
		['name' => 'config#state', 'url' => '/state', 'verb' => 'GET'],
		['name' => 'config#refreshCollections', 'url' => '/collections/refresh', 'verb' => 'POST'],
		['name' => 'config#saveSelection', 'url' => '/collections', 'verb' => 'PUT'],
		['name' => 'config#sync', 'url' => '/sync', 'verb' => 'POST'],
		['name' => 'admin#save', 'url' => '/admin-config', 'verb' => 'PUT'],
	],
];

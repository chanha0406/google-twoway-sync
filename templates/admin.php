<?php
/** @var array $_ */
$l = \OCP\Util::getL10N('googletwowaysync');
$state = json_encode($_['state'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$secretSet = !empty($_['state']['secretSet']);
?>

<div id="gtsync-admin" data-state="<?php p($state); ?>">
	<p class="lead">
		<?php p($l->t('Create a separate OAuth client in Google Cloud for this app. Do not reuse the Social Login client. Login only asks for the email and profile. This app asks for write access to calendar, contacts, and tasks, and it uses a different redirect URI.')); ?>
	</p>
	<p class="lead">
		<?php p($l->t('In Google Cloud, create a Web application client, enable the Google Calendar API, People API, and Tasks API, and register the redirect URI below.')); ?>
	</p>
	<p id="gtsync-admin-source" class="note"></p>
	<code id="gtsync-admin-redirect"></code>

	<label class="field" for="gtsync-client-id"><?php p($l->t('Client ID')); ?></label>
	<input id="gtsync-client-id" type="text" autocomplete="off" spellcheck="false">

	<label class="field" for="gtsync-client-secret"><?php p($l->t('Client secret')); ?></label>
	<input id="gtsync-client-secret" type="password" autocomplete="new-password" spellcheck="false" placeholder="<?php p($secretSet ? $l->t('Saved. Enter a new secret only to change it') : ''); ?>">
	<p class="note"><?php p($l->t('Leave the secret empty to keep the saved one. Clear the client ID and save to stop using this app\'s client and fall back to Google Synchronization.')); ?></p>

	<div class="actions">
		<button id="gtsync-admin-save" type="button" class="primary"><?php p($l->t('Save')); ?></button>
	</div>
	<p id="gtsync-admin-status" class="note" role="status"></p>
</div>

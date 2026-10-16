<?php
/** @var array $_ */
$l = \OCP\Util::getL10N('googletwowaysync');
$state = json_encode($_['state'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>

<div id="gtsync" data-state="<?php p($state); ?>">
	<p class="lead">
		<?php p($l->t('Syncs Google calendars, contacts, and tasks with Nextcloud in both directions. A change on one side is applied to the other. If the same item changes on both sides, the later change is kept. If one side deletes it and the other edits it, the edit is kept. Sync runs each time server cron runs (about every 5 minutes).')); ?>
	</p>
	<p class="lead">
		<?php p($l->t('Calendars that Google does not allow writing to, such as holidays, are imported only. Edits in Nextcloud are reverted on the next sync. Contacts that were already in the address book when sync is first turned on are not uploaded, and only contacts created afterwards are sent to Google. Turning on a task list creates a separate calendar for that list. It stays separate from event calendars and shows up in the Tasks app. Google tasks have no time of day, so only the date is synced.')); ?>
	</p>
	<p class="lead">
		<?php p($l->t('This app keeps working when Google Synchronization is turned off. It still uses that app\'s OAuth client settings, and cron does not run the disabled app\'s import jobs. Writing starts after you connect the Google account again on this page. Turning the old app back on resumes its import, so leave it off after the switch.')); ?>
	</p>

	<section class="block">
		<h3><?php p($l->t('Google account')); ?></h3>
		<p id="gtsync-account" class="note"></p>
		<p class="note"><?php p($l->t('The redirect URI below must be registered on the OAuth client in Google Cloud Console.')); ?></p>
		<code id="gtsync-redirect"></code>
		<div class="actions">
			<button id="gtsync-connect" type="button" class="primary"><?php p($l->t('Connect Google')); ?></button>
			<button id="gtsync-disconnect" type="button"><?php p($l->t('Disconnect')); ?></button>
		</div>
	</section>

	<section class="block">
		<h3><?php p($l->t('Calendars')); ?></h3>
		<ul id="gtsync-calendars" class="collections"></ul>
	</section>
	<section class="block">
		<h3><?php p($l->t('Contacts')); ?></h3>
		<ul id="gtsync-contacts" class="collections"></ul>
	</section>
	<section class="block">
		<h3><?php p($l->t('Tasks')); ?></h3>
		<ul id="gtsync-tasks" class="collections"></ul>
	</section>
	<div class="actions">
		<button id="gtsync-save" type="button"><?php p($l->t('Save selection')); ?></button>
		<button id="gtsync-preview" type="button"><?php p($l->t('Preview')); ?></button>
		<button id="gtsync-sync" type="button" class="primary"><?php p($l->t('Sync now')); ?></button>
		<button id="gtsync-refresh" type="button"><?php p($l->t('Reload list')); ?></button>
	</div>
	<p id="gtsync-status" class="note" role="status"></p>
	<div id="gtsync-result" class="result" hidden></div>
</div>

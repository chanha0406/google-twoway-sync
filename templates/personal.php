<?php
/** @var array $_ */
$state = json_encode($_['state'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>

<div id="gtsync" data-state="<?php p($state); ?>">
	<p class="lead">
		구글 캘린더, 연락처, 할 일을 Nextcloud와 양방향으로 맞춥니다. 한쪽에서 고치면 다른 쪽에도 반영되고,
		양쪽에서 같은 항목을 고쳤으면 더 나중에 고친 쪽을 남깁니다. 한쪽은 지우고 다른 쪽은 고쳤으면 고친 내용을 남깁니다.
		서버 cron이 돌 때마다(약 5분) 동기화합니다.
	</p>
	<p class="lead">
		공휴일·절기처럼 구글에서 쓸 수 없는 캘린더는 가져오기만 하고, Nextcloud에서 고친 내용은 다음 동기화 때 되돌립니다.
		연락처는 처음 켤 때 주소록에 있던 비구글 연락처는 올리지 않고, 그 뒤 새로 만든 연락처만 구글에 올립니다.
		할 일 목록을 켜면 그 목록만 담는 캘린더가 따로 생깁니다. 일정 캘린더와는 섞이지 않고, 할 일 앱에 나옵니다. 구글 할 일에는 시각이 없어서 날짜만 맞춥니다.
	</p>
	<p class="lead">
		Google Synchronization을 꺼도 이 앱은 동작합니다. 그 앱의 OAuth 클라이언트 설정은 그대로 쓰고, 꺼진 앱의 가져오기 작업은 cron이 실행하지 않습니다.
		쓰기는 이 화면에서 구글 계정을 다시 연결한 뒤에 됩니다. 예전 앱을 다시 켜면 가져오기가 되살아나므로, 넘긴 뒤에는 켜지 마세요.
	</p>

	<section class="block">
		<h3>구글 계정</h3>
		<p id="gtsync-account" class="note"></p>
		<p class="note">Google Cloud 콘솔의 OAuth 클라이언트에 아래 리디렉트 URI가 등록되어 있어야 합니다.</p>
		<code id="gtsync-redirect"></code>
		<div class="actions">
			<button id="gtsync-connect" type="button" class="primary">구글 연결</button>
			<button id="gtsync-disconnect" type="button">연결 해제</button>
		</div>
	</section>

	<section class="block">
		<h3>캘린더</h3>
		<ul id="gtsync-calendars" class="collections"></ul>
	</section>
	<section class="block">
		<h3>연락처</h3>
		<ul id="gtsync-contacts" class="collections"></ul>
	</section>
	<section class="block">
		<h3>할 일</h3>
		<ul id="gtsync-tasks" class="collections"></ul>
	</section>
	<div class="actions">
		<button id="gtsync-save" type="button">선택 저장</button>
		<button id="gtsync-preview" type="button">미리보기</button>
		<button id="gtsync-sync" type="button" class="primary">지금 동기화</button>
		<button id="gtsync-refresh" type="button">목록 다시 가져오기</button>
	</div>
	<p id="gtsync-status" class="note" role="status"></p>
	<div id="gtsync-result" class="result" hidden></div>
</div>

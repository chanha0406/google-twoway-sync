# 추가 수정 재검토

- 작성일: 2026-10-23
- 근거: 브랜치 `main`, `git rev-parse --short HEAD` = `1a10fdb`. 검토 시작 시 `git status`는 `origin/main`과 같고 작업 트리가 깨끗했다. `.gitmodules` 없음.
- 상태: 초안

읽은 파일:

- `CHANGELOG.md`, `README.md`, `appinfo/info.xml`
- `lib/Service/SyncService.php`, `SyncDecision.php`, `PlanRunner.php`, `SyncContext.php`, `SyncReport.php`, `GoogleClient.php`
- `lib/Service/CalendarSyncService.php`, `CalendarTrash.php`, `CalendarAppearance.php`, `Appearance.php`, `EventConverter.php`
- `lib/Service/ContactSyncService.php`, `ContactConverter.php`
- `lib/Service/TaskSyncService.php`, `TodoIcs.php`
- `lib/Command/Sync.php`
- `lib/Db/ObjectState.php`, `lib/Migration/Version1000Date20261014150000.php`, `lib/Migration/Version1001Date20261019120000.php`
- `tests/Unit/SyncDecisionTest.php`, `AppearanceTest.php`, `ContactPhotoUrlTest.php`, `ContactPhotoBytesTest.php`

`vendor`, `node_modules`, `l10n`, 설치본은 읽지 않았다. 앱 소스는 수정하지 않았다.

## 판정

코드 수정은 사용자가 다시 정하기 전에 하지 않는다. 저장 주소를 `=s100`으로 남기는 수정은 넣지 않았다.

저장 주소는 `photoUrl()`이 만드는 `=s0`이 맞다. `=s100`은 구글이 목록에 주는 크기일 뿐이라, 그 문자열을 그대로 두는 것은 수정이 아니다.

`lib/Service/ContactSyncService.php`의 `PUSH_UPDATE`는 사진을 올리지 않아도 `saveState`에 `photoUrl($person)` 또는 `photoUrl($updated)`를 넣는다. 그 값은 `ContactConverter::fullSizePhotoUrl()`을 거친 `=s0`이다. 카드 바이트는 썸네일 그대로일 수 있다.

잘못된 점은 `=s0`로 저장한 것만으로 카드 바이트가 원본이라고 보고, 다음 `--force-photos`가 그 썸네일을 구글 원본 위에 올릴 수 있다는 것이다. `photoUpNeeded()`는 `storedPhotoStale()`이 참일 때만 업로드를 거절하는데, 저장된 주소가 이미 `=s0`이면 그 가드가 거짓이 된다. 해당 `saveState`는 두 곳이다.

- 필드 diff가 없고 `photoUp`이 거짓일 때 (`photoUrl($person)`)
- 필드만 반영하고 사진은 보내지 않았을 때 (`photoUrl($updated)`)

## 봤지만 탈락한 후보

- Appearance를 다른 앱과 공통 패키지로 빼는 것, `SyncDecision`/`PlanRunner`를 이 앱 밖에 두는 것. 유지하기로 한 구성이다.
- 캘린더 `pushFailureFails`를 켜는 것. 일부러 꺼 둔 동작이다. 푸시 실패가 런 전체를 실패로 만들지 않아도, 예외 업로드 실패는 `pendingOverrides`와 etag 유지로 다음 풀이 로컬 예외를 지우지 않게 되어 있다.
- 쓸 수 있는 캘린더에서 할당량으로 끊기면, pending이 하나라도 있을 때 다음 런이 PUSH가 되어 아직 시도하지 않은 onlyLocal 예외도 같이 올라가는 것. 읽기 전용은 로컬 전용 예외를 되돌리는 것. 유지하기로 한 동작이다.
- `PlanRunner::finish()`가 `$ok`가 거짓이어도 `initialized`를 1로 둔다. 11.1.6의 조건은 그 플래그다. 캘린더는 토큰이 없으면 다음 런이 전체 목록을 다시 읽고, 구글 id와 URI가 같은 일정은 로컬 전용 삭제보다 먼저 adopt된다. 실패 런을 아직 초기화가 아닌 것으로 보는 해석은 이미 고른 조건과 다르다.
- 일정 `VALARM`은 `reminders`가 diff에 있을 때만 다시 쓴다. 리마인더는 양방향 필드다. 할 일 알람을 남기는 `TodoIcs::merge`와 같은 요구가 일정에 확인되어 있지는 않다.
- 연락처 `categories`는 `PULL_ONLY`다. 그룹은 별도 API가 필요하다는 주석이 있다. 풀 때 로컬에만 있는 분류가 바뀌는 것은 그 설계다.
- 할 일 `PUSH_DELETE`는 404를 삼키지 않는다. 다음 런에서 `fetchTask`가 404면 `forget`이다. 한 런이 실패하는 것이지, 항목이 지워진 채로 고정되지는 않는다.
- 구글 색이 비어 있을 때 `Appearance::decide`가 `appliedColor`를 로컬 색으로 심는다. 빈 desired는 쓰지 않고, 나중에 구글 색이 생기고 로컬이 applied와 같으면 따라간다. 첫 비교에서 로컬 색을 덮어쓰지 않는 테스트는 있다. 그 시드를 버그로 단정할 확인은 없다.
- `occ googletwowaysync:sync` 출력은 캘린더와 연락처만 묶고 할 일은 Other로 간다. 화면 분류이고, 데이터 삭제나 쓰기 실패는 아니다.

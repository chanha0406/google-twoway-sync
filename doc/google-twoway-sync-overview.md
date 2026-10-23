# Google Two Way Sync 개요

- 작성일: 2026-10-23
- 근거: `appinfo/info.xml`, `README.md`, `CHANGELOG.md`, 루트 디렉터리 목록, `git remote -v`, `git rev-parse --short HEAD`, `git status`, `git log`, `.gitmodules` 유무
- 상태: 초안

## 목적

Nextcloud 앱이다. 앱 id는 `googletwowaysync`다. 표시 이름은 `appinfo/info.xml`의 `<name>`인 Google Two Way Sync다. 같은 파일의 한국어 이름은 구글 양방향 동기화다.

`README.md`와 `info.xml`에 적힌 목적은 Google 캘린더, 연락처, 할 일을 Nextcloud와 양방향으로 동기화하는 것이다. 할 일 목록마다 할 일만 담는 캘린더가 따로 생기며, 같은 항목을 양쪽에서 고치면 나중에 고친 쪽을 남긴다. 버전은 `info.xml`의 `11.1.8`이다.

## 저장소 구성

이 디렉터리가 제품 코드 저장소다. `.gitmodules`는 없다.

원격은 `git remote -v` 기준이다.

- `origin`: `git@github.com:chanha0406/google-twoway-sync.git` (fetch, push)
- `backup`: `ssh://git@git.chanha.kr:2224/chanha0406/google-twoway-sync.git` (fetch, push)

검토 시점의 브랜치는 `main`이고 `origin/main`을 추적한다. `git rev-parse --short HEAD`는 `1a10fdb`다. 문서 작성을 시작하기 전 `git status`는 작업 트리가 깨끗한 상태였다.

`info.xml`의 website, bugs는 `https://github.com/chanha0406/google-twoway-sync`와 `https://github.com/chanha0406/google-twoway-sync/issues`다.

루트에서 확인한 디렉터리는 `appinfo`, `css`, `img`, `js`, `l10n`, `lib`, `templates`, `tests`, `vendor-bin`이다. `doc/`은 이 문서 작업으로 만들었다. `docs/`는 없었다.

설치 위치, 운영 서버, 계정 구성은 이 저장소에서 확인하지 않았다. 미확인.

## 주요 기능

`info.xml`, `README.md`, `lib/Command/Sync.php`에서 확인한 범위다.

- 캘린더, 연락처, 할 일 목록의 양방향 동기화. 백그라운드 잡과 `occ googletwowaysync:sync`가 있다.
- `occ` 옵션으로 확인한 것: `--preview`, `--legacy-token`(미리보기와 함께만), `--collection`, `--allow-mass-delete`, `--force-photos`, `--details`.
- 개인 설정과 관리자 설정 화면이 `info.xml`에 등록되어 있다. 화면의 실제 동작은 이번 검토에서 열지 않았다. 미확인.

동기화 구현의 세부 판정은 [analysis/2026-10-23-additional-fix-review.md](analysis/2026-10-23-additional-fix-review.md)에 둔다.

# AGENTS.md

## 2-1 워크스페이스

이 루트(`/home/chanha0406/Workspace/google-twoway-sync`)가 제품 코드 저장소다. Nextcloud 앱 소스가 이 루트에 있다.

서브모듈은 없다(`.gitmodules` 없음). 나중에 서브모듈이 생기면 참고용이며 읽기 전용으로 취급한다. 루트 커밋에는 서브모듈 커밋 포인터만 기록된다. 사용자가 명시적으로 요청하지 않으면 서브모듈 내부를 수정하지 않는다. 하위 저장소에 자체 `CLAUDE.md` 또는 `AGENTS.md`가 있으면, 그 저장소 안에서는 그 규칙이 우선한다.

모든 산출물은 루트 `doc/`에 둔다. 상시 개요는 [doc/google-twoway-sync-overview.md](doc/google-twoway-sync-overview.md)다.

## 2-3 작업 언어

설명, 문서, 기록은 한국어로 쓴다. 코드 식별자와 기술 용어는 영문으로 둔다.

## 2-4 문서화 규칙

| 종류 | 저장 위치 | 파일명 |
| --- | --- | --- |
| 분석·조사 | doc/analysis/ | YYYY-MM-DD-<주제>.md |
| 계획 | doc/plans/ | YYYY-MM-DD-<주제>.md |
| 설계 | doc/design/ | YYYY-MM-DD-<주제>.md |
| 수정사항 | doc/changes/ | YYYY-MM-DD-<주제>.md |
| 아키텍처 | doc/architecture/ | <주제>.md (갱신형) |
| 백로그 | doc/backlog/ | YYYY-MM-DD-<주제>.md |
| 미구현·보류 | doc/unimplemented/ | YYYY-MM-DD-<주제>.md |
| 작업 로그 | doc/worklog/ | YYYY-MM-DD.md |
| 상시 개요 | doc/ 루트 | google-twoway-sync-overview.md |

하위 폴더는 첫 문서를 쓸 때 만든다. 이미 다룬 주제는 새 파일 대신 기존 파일을 갱신한다. 계획이 설계·구현으로 이어지면 서로 링크한다. 파일 상단에 작성일, 근거, 상태를 둔다. 확인하지 못한 내용은 추측하지 말고 미확인으로 적는다. 서브모듈을 읽었으면 브랜치와 짧은 커밋 해시를 적는다. 작업이 끝나면 `doc/worklog`에 로그하고 `doc/README.md` 문서 목록을 갱신한다. 목록, 로그, 백로그, 아키텍처, 미구현 내용은 이 파일에 직접 쓰지 않는다. 단일 출처는 `doc/README.md`다.

## 2-5 용어 규칙

구성요소가 있는지는 가정하지 말고 파일시스템에서 확인한다.

- 외부 표기: ___ / 내부 표기: ___ / 금지 표기: ___

## 2-7 기록 위치

문서 목록과 작업 로그의 단일 출처는 `doc/README.md`다. 이 파일에는 규칙만 둔다.

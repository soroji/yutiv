# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.1] - 2026-09-08

### Added

- 다국어 입력 컴포넌트의 언어 탭 라벨(`common.language_ko` / `_en` / `_ja` / `_zh-CN`) 번역을 추가했습니다.
- 환경설정 > 일반의 사이트 설명 입력 안내 문구(`admin.settings.general.site_description_hint`) 번역을 추가했습니다.

## [1.0.0] - 2026-09-08

### Added

- G7 기본 관리자 템플릿(sirsoft-admin_basic) 중국어 간체(zh-CN) 언어팩을 신규 제공합니다. 관리자 화면의 내비게이션·대시보드·확장 관리·환경설정·레이아웃 편집기 문구가 중국 본토 표기 기준의 간체 중국어로 표시됩니다.
- 프론트엔드 번역 11종(진입 파일 1종 + partial 10종)을 추가했습니다 — 관리자 공통 문구(`admin`), 레이아웃 편집기(`editor`·`layout_editor`), 확장 관리(`extensions`), 인증(`auth`), 내비게이션(`nav`), 공통(`common`), 첨부파일(`attachment`), 오류(`errors`), 국가 목록(`countries`).
- 초기 데이터 번역 1종을 추가했습니다 — 템플릿 표시명·설명.
- 컴포넌트 이름·slot 키·라우트 이름·설정 키·아이콘 클래스·placeholder 는 원본 그대로 보존했습니다.

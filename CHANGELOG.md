# Changelog

## [1.1.0](https://github.com/vandetho/symflow-laravel/compare/v1.0.1...v1.1.0) (2026-05-01)


### Features

* **engine:** add ListenerErrorMode for listener error containment ([faba4ff](https://github.com/vandetho/symflow-laravel/commit/faba4ff58a9413b1a1603aacee23c500f011ff0d))
* **engine:** add per-transition scoping and priority to on() ([d26e5a6](https://github.com/vandetho/symflow-laravel/commit/d26e5a68564e2954dc8516a58af918b64fea6c6d))
* **engine:** allow guards to return structured GuardResult with reason/code ([fe6b603](https://github.com/vandetho/symflow-laravel/commit/fe6b6033abe11dba87722a4feea432b8e321dd38))
* **engine:** make Guard event blockable from listeners ([ee75b4d](https://github.com/vandetho/symflow-laravel/commit/ee75b4dccb17d80cb842ce86ea92331594c644a6))


### Bug Fixes

* **subject:** mirror prior block state into SubjectGuardEvent ([e6a3f97](https://github.com/vandetho/symflow-laravel/commit/e6a3f9778235261f2c8b17b19dc8f5d0d152a007))

## [1.0.1](https://github.com/vandetho/symflow-laravel/compare/v1.0.0...v1.0.1) (2026-04-28)


### Documentation

* add CONTRIBUTING and SECURITY guides; link license badge to LICENSE ([7a60c51](https://github.com/vandetho/symflow-laravel/commit/7a60c51d6d10cfcf17bda821dfa39b5f284b753c))

## 1.0.0 (2026-04-28)


### Features

* **export:** add SvgExporter with auto-layout and dark/light theme ([4657036](https://github.com/vandetho/symflow-laravel/commit/465703676597fdf2f3efcb74c9f0478a720d1910))
* **import:** handle !php/const and !php/enum YAML tags ([5a257e4](https://github.com/vandetho/symflow-laravel/commit/5a257e45312e9f1c6238977d097fcaa65f5e267e))
* initial laraflow package — Laravel port of symflow ([d4a44f2](https://github.com/vandetho/symflow-laravel/commit/d4a44f291bb5673aaf189ebdb201d1eb9289b98e))


### Bug Fixes

* restore README.md accidentally removed in previous commit ([331238b](https://github.com/vandetho/symflow-laravel/commit/331238b5c9d29ac01dd6868d2c6791e5c6af064a))


### Documentation

* add README, CLAUDE.md, and full documentation ([9464c71](https://github.com/vandetho/symflow-laravel/commit/9464c71b6a9a18ce9db8914d50919bfebaf10e24))
* document SVG exporter and release-please flow; drop monorepo claim ([7c8f6cc](https://github.com/vandetho/symflow-laravel/commit/7c8f6ccf51557afed755e74b5efac7f7ce44d07e))

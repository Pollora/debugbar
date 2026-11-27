# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- Development only: `antecedent/patchwork` is required at commit `d588654` of its `master` branch (antecedent/patchwork#235), aliased as 2.2.6, so that Pest mutants are no longer counted as caught when tests hang. This pin is temporary until the next Patchwork release

## [1.1.1] - 2026-11-19

### Changed

- The quality workflow runs the harness at v0.1.32 (it ran v0.1.30), and `composer.json` requires `pollora/harness` `^0.1.32`. No change to the code of the package.

## [1.1.0] - 2026-11-19

### Security

- The bar is now reserved to signed-in users holding a WordPress capability, `manage_options` by default (`debugbar-pollora.capability`, `DEBUGBAR_POLLORA_CAPABILITY`). Until now only the environment (`APP_DEBUG` / `DEBUGBAR_ENABLED`, never production) and a local address guarded it, so on a staging site in debug mode any visitor saw SQL queries, options and file paths. A user without the capability gets no bar (front end or wp-admin), nothing collected or stored for their request, no `phpdebugbar-id` header, and a 403 from Debugbar's endpoints (stored requests, assets, cache, query explain) and from the doctor. The check fails closed: without WordPress, or with a blank setting, the default capability applies. The console and the test suite are unchanged.

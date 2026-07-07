# Contributing to milpa/example-agent-ready-blog

Thanks for your interest in contributing! This repo is a runnable example of the Milpa
loop — `plugin → capability → tool → verification → event → result` — as a tiny
agent-ready blog: a `Kernel`, three plugins, and a handful of contract-faithful inline
implementations (`Container`, `EventDispatcher`, `CapabilityGraph`, `Router`) small enough
to read in one sitting.

## Getting started

```bash
composer install
vendor/bin/phpunit --testsuite ExampleBlog
vendor/bin/phpstan analyse --no-progress
php bin/blog.php --auto-approve
```

These run in CI on PHP 8.3 and 8.4 (alongside `composer validate --strict`, a `php -l`
syntax pass, and the loop smoke test — `bin/blog.php` run both `--auto-approve` and
`--reject`); run them locally before opening a PR.

## Guidelines

- **PHP >= 8.3**, with `declare(strict_types=1);` in every file.
- **Respect the tier boundary.** This is an **example app that consumes the family** —
  it depends on `milpa/core`, `milpa/http`, and `milpa/tool-runtime`, never the reverse.
  Do not introduce a dependency on Doctrine or any product/plugin code, and keep the
  three published packages as the only source of framework contracts: anything this repo
  implements inline (`src/App/*`) exists to demonstrate how small those contracts are to
  satisfy, not to become a fourth package.
- **[Conventional Commits](https://www.conventionalcommits.org/)** are preferred for a
  readable history, but this repo does **not** run release-please — examples don't
  version in lockstep with the family. Tags are cut manually.

## Code style

The whole Milpa family (`milpa/core`, `milpa/http`, `milpa/tool-runtime`) shares one
coding standard, committed verbatim in every repo as `.php-cs-fixer.dist.php` and
enforced by CI. In short:

- **[PSR-12](https://www.php-fig.org/psr/psr-12/) base**: 4 spaces (never tabs);
  opening braces on the **next line** for classes and methods, on the **same line**
  for control structures; one statement per line.
- **Family deltas on top of PSR-12**: short array syntax (`[]`), one space around
  string concatenation (`$a . $b`), fully-multiline method arguments when split,
  no unused imports, aligned/separated/trimmed PHPDoc tags, trailing commas in
  multiline constructs.

Check and fix locally before pushing:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff   # what CI runs
vendor/bin/php-cs-fixer fix                    # apply
```

Do not tweak `.php-cs-fixer.dist.php` in this repo alone — the standard changes in
lockstep across the family or not at all.

## Pull requests

Keep PRs focused, add tests for behavior changes, and make sure the commands above are
green. A maintainer will review and tag a new `v0.x` release by hand once merged to `main`.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE).

---

Milpa is developed and maintained by [TeamX Agency](https://teamx.agency).

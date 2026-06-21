<!--
PR title MUST be a Conventional Commit (it becomes the squash-merge subject and
the release-note line). e.g. "feat: add per-job bandwidth limit", "fix: ...".
Types: feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert
-->

## What

<!-- The change, in a sentence or two. -->

## Why

<!-- The problem/need this addresses. -->

## Validation

<!-- How you verified it: tests added/updated, `composer test` / `composer cs` /
`composer stan` results, and any on-tower check. -->

- [ ] `composer test` (PHPUnit) green
- [ ] `composer cs` (php-cs-fixer) clean
- [ ] `composer stan` (PHPStan) clean
- [ ] Verified on a live Unraid box (or N/A — explain)

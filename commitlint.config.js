// commitlint — enforce Conventional Commits on the PR title.
//
// The repo squash-merges PRs, so the PR TITLE becomes the commit subject on
// main (and feeds the auto-generated GitHub Release notes). CI lints that title
// against the conventional spec (feat:, fix:, docs:, chore:, ci:, build:,
// refactor:, test:, style:, perf:, revert:).
module.exports = {
    extends: ['@commitlint/config-conventional'],
};

# Conventional Commits

Meridian uses Conventional Commits for commit and PR titles so history stays readable and future release automation remains possible.

## Format

```text
type(scope): summary
type(scope)!: summary
type: summary
type!: summary
```

Allowed types:

- `build`
- `chore`
- `ci`
- `docs`
- `feat`
- `fix`
- `perf`
- `refactor`
- `revert`
- `style`
- `test`

Examples:

```text
docs(process): add traceability matrix
ci(process): validate PR bodies
chore(repo): add package scripts
feat(attendance): add check-out action
fix(sync): make node operation apply idempotent
```

Use `!` for breaking changes:

```text
feat(api)!: replace attendance response shape
```

## PR Titles

Pull request titles must also use Conventional Commits. If the project uses squash merging, the PR title can become the final commit message without cleanup.

## Validation

Run:

```bash
npm run commit:check -- --message "docs(process): add commit policy"
```

GitHub Actions validate:

- pull request titles on PRs;
- pushed commit messages on `main`.

Generated merge commits such as `Merge pull request #123 ...` are ignored by the validator because they are produced by GitHub rather than written by contributors.

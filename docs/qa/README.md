# Meridian QA Docs

Human QA scenarios live in this directory and use IDs like `QA-BOOT-01`.

Every `docs/qa/QA-*.md` file must include these sections:

- Purpose
- Requirements covered
- Environment
- Personas
- Setup data
- Steps
- Expected results
- Evidence to capture
- Failure notes

Run the local process checks before opening a PR:

```bash
scripts/process/check.sh
```

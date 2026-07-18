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

On Windows, open Git Bash in the repository and run this command there so the same POSIX script is used across Windows, Linux, and macOS. Do not use Windows PowerShell, `cmd.exe`, or the WSL `bash.exe` shim for this check.

## Alpha 1 Field Report script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-FR-01-offline-field-report.md`](QA-FR-01-offline-field-report.md) | Offline Field Report submit with title/photos, reconnect/FRA, immutability, IC visibility, photo upload pending state, Name References, and `ic_lead`-only photo download | M9.9 |

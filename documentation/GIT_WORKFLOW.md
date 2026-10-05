# Git workflow

Use one branch per task: `feature/<description>` for additions and `bugs/<description>` for defects. This applies to code, tests, configuration and documentation. Keep `main` as the integrated baseline.

Before starting, fetch remote changes, check the working tree, and branch from current `main`. Preserve any outstanding work on its own branch instead of discarding it. Commit completed, validated increments and push the branch for review. Merge only within the user's authorized scope; after merge, return to updated `main` and delete the completed branch locally and remotely once its history is verified as merged.

Run verification appropriate to the task and record its practical limits. A frontend build or mocked browser test does not prove hosted database migration, real identity sign-in or notification delivery. Release ZIPs are generated locally and ignored for new releases; source, maintained docs and migrations remain versioned. Previously tracked deployment records stay as historical records.

Never stage all files without reviewing the candidate list. Exclude private environment files, database exports, attachments, dependency/build output and duplicate scratch assets. Frontend environment examples and intentionally tracked public frontend configuration contain browser-visible values only; private secrets belong in ignored server configuration.

The 5 October 2026 checkpoint consolidates accumulated work on a temporary feature branch before integrating it into `main`. Older `codex/` branches may be removed only after checking ancestry. Future tasks follow the branch rules in the repository's [AGENTS.md](../AGENTS.md).

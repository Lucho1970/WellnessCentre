# Repository workflow

- Keep `main` clean. Before changing source, tests, configuration, or documentation, switch to a dedicated branch.
- Use `feature/<short-description>` for additions and planned development; use `bugs/<short-description>` for fixes. These user-requested prefixes override the generic `codex/` default.
- Each new task should have its own branch. Check existing branches and working-tree changes before switching; preserve unfinished work and never reset or discard it to start a task.
- Commit meaningful completed changes with the relevant validation evidence. Do not let completed tasks accumulate as uncommitted edits on `main`.
- Run checks appropriate to the change. A broad checkpoint requires frontend build, browser regression, and applicable PHP checks; distinguish local checks from hosted database acceptance.
- Push completed branches to `origin`. Merge reviewed, validated work into `main` according to the user's authorized scope. For a task that only asks for implementation, do not infer authorization to merge it; present the branch for review.
- After an authorized merge, update local `main` and remove the completed local and remote branch when it is no longer needed. Verify it is merged and not used by another worktree; do not force-delete unmerged branches.
- Keep private `.env` files, database dumps, local attachments, dependencies, build output and generated release ZIPs out of new commits. Existing tracked release records are historical; do not delete their files merely to clean Git status.
- Deployment packages do not replace Git commits. Keep deployment instructions, source migrations, tests and maintained documentation versioned. Record the source revision in release manifests when generating packages.

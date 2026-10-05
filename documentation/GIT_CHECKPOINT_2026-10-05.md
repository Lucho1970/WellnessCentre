# Git checkpoint — 5 October 2026

The working tree accumulated source and documentation changes after the 27 September commit. They were preserved on `feature/development-checkpoint-2026-10-05` in commit `6ecbeb8` before integration into `main`. This is a consolidated baseline, not a claim that every feature has hosted acceptance.

The checkpoint includes scheduling/notification development, guest portal booking and profile work, the generic portal landing, public website domain/runtime configuration, practitioner external links, mobile footer fixes, migrations 021–031, and MT0 architecture documentation. Existing four local commits ahead of origin are preserved in the integration history.

Generated deployment folders, local attachments, the local database dump, and duplicate branding scratch files remain on disk and are ignored for new commits. Previously tracked release records are retained. Private environment files remain ignored. A staged-path/credential-pattern review found no private keys or credential patterns; the mail documentation assignment is a placeholder.

## Local validation

- Frontend bilingual content and 1,400 translation keys, TypeScript, and both production builds passed.
- All 38 top-level local PHP tests passed with the Git OpenSSL configuration.
- All 13 production-build browser smoke tests passed.
- The full browser regression run passed 98 of 101 cases initially. Three failures came from outdated test setup: two profile tests received an empty public-card fixture, and one directory test looked for a heading after the name became a link. `bugs/portal-regression-fixtures` corrects those fixtures/assertions; all three failed cases passed on rerun. No production behavior was changed for those test fixes.

Hosted database isolation, provider delivery and live migration acceptance remain separate checks. This cleanup does not deploy anything to Netfirms or change identity provisioning.

## Branch retirement and future work

Ancestry checks confirmed that `codex/admin-notification-status`, `codex/calendar-reminder-alarms`, `codex/netfirms-mail-bridge`, and `codex/practitioner-calendar-view` contain no commits outside the existing main history. They are eligible for local deletion, and their remote counterparts are eligible for removal after confirming main is pushed. The temporary checkpoint and fixture-fix branches are likewise retired only after integration.

Future work uses one `feature/<description>` or `bugs/<description>` branch per task under [the repository workflow](GIT_WORKFLOW.md). Source commits and remote sync replace the earlier reliance on deployment ZIPs as checkpoints.

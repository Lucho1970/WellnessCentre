# Repository integration and registration foundation — 26 October 2026

The owner requested up-to-date Git, integration/removal of old branches, updated documentation
and the start of registration/subscription development.

Fetched origin with pruning. Local main matched origin/main at 4657d13. The only remaining
historical branch was bugs/travel-enquiries-mysql57 at 2a867b3. It was not an ancestor, but its
implementation had been carried forward by the reviewed restore/polling changes already on
main. Inspected the full original patch and conflicts: retain main's shared MySQL-safe unread
condition, expanded 28-check SQL test, current release documentation and importer runner option.
The original MySQL runner was already identical. No unique branch implementation was lost.

Created integration merge 9030359 with exactly the same tree as the prior main. Pushed main
to origin, verified the old branch was an ancestor of both main and origin/main, checked it
was not used by another worktree, removed its remote ref and used ordinary git branch -d
locally. No force deletion or history rewriting. Origin then contained only main.

Started feature/registration-billing-foundation from that integrated main before editing the
new source, migration, tests or documentation. The new branch is active development, not an
obsolete branch. Its first slice is described and validated in
[Registration and billing](REGISTRATION_BILLING_PLAN.md). It is pushed for review separately
from the historical integration; main remains the integrated pre-development baseline.

Frontend build and broad browser regression were exercised; three original-run timeouts passed
focused reruns, and two tests remain skipped. PHP and real MySQL 5.7/MariaDB acceptance passed.
The feature contract records exact counts and local/hosted limitations. No hosted deployment,
database migration or payment/resource provisioning was performed.

The existing untracked service spreadsheets/CSVs, branding images and documentation/For Sales
files were preserved and excluded from commits. Private .env, dependencies, build output,
synthetic databases and generated packages were not added. Untracked user assets mean git
status is not empty; there are no unexplained tracked application edits on main.

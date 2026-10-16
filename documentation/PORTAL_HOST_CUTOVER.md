# Portal hosting and deployment

The current deployment uses one common clinic portal directory. See [Deployment layout](DEPLOYMENT_LAYOUT.md) for the confirmed folder map, package names, configuration, rollout and verification steps.

Both `livinlively.copihue.ca` and `wellnexa.copihue.ca` serve `/public_html/wellness-common-portal`. Upload `wellness-common-portal.zip` once there. The general `portal.copihue.ca` landing and administration application remain in `/public_html/wellness-portal` and use `copihue-portal-landing.zip`. Marketing sites remain in `/public_html/livinlively.ca` and `/public_html/wellnexa.ca`. The shared API remains private at `/wellness-api`.

No SQL migration is required solely for this hosting layout. Feature migrations retain their own prerequisites and order. Keep all active clinic callback/logout registrations; never redirect one clinic's booking flow to another clinic. Previously generated release archives and manifests are historical and have not been modified.

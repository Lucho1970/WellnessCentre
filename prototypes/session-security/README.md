# Local session-security feasibility harness

This is a synthetic experiment, separate from the application and its PHP API.
Do not deploy this directory or the frontend prototype. It has no database,
membership, patient, billing, mail, SMS or real identity-provider connection.

From `Frontend`, using the existing locked dependencies and PHP CLI:

```powershell
npm run typecheck:session-prototype
npm run lint
npm run test:session-prototype
```

The latest matrix/proof contract is in the [ID-proof correction report](../../documentation/SESSION_PROOF_CONTRACT_2026-11-02.md).
ID proofs require their exact SPA audience and allow an omitted azp; any present
invalid azp is rejected. API tokens still require the approved caller azp and scope.
The synthetic issuer omits ID azp by default, exercising this through the real SDK.
The final 168-case run passes 56/56 in Edge, 56/56 in Firefox and 52/56 in Windows
WebKit; its four strict SameSite failures remain. Exact idle-boundary tests use an
explicit authenticated fixture freeze control; other scenarios use the live clock.
The prior browser investigation
passed 10 focused cancellation repeats. The independent cookie/cross-site POST probe confirms the
Windows WebKit build sends a dummy Lax cookie on cross-site POST. Keep the failing
checks; actual Safari/macOS/iOS and trusted HTTPS acceptance remain unverified.

For the Windows Firefox activation issue, use an isolated, process-scoped browser
cache. From `Frontend`:

```powershell
$previousBrowserCache = $env:PLAYWRIGHT_BROWSERS_PATH
try {
    $env:PLAYWRIGHT_BROWSERS_PATH = [IO.Path]::GetFullPath((Join-Path (Get-Location) '../.tmp/session-browsers'))
    npx playwright install firefox webkit
    npm run test:session-prototype
    node scripts/check-session-browser-capabilities.mjs
} finally {
    $env:PLAYWRIGHT_BROWSERS_PATH = $previousBrowserCache
}
```

This downloads only the locked test-browser builds; no global cache replacement or
system dependency change. Both validation commands currently exit 1 for WebKit.
The independent probe uses only fixed synthetic cookies on two random-port loopback
HTTP servers and intercepted fake HTTPS; it never loads the application or provider.

The dedicated Playwright configuration starts Vite and PHP on loopback ports 5196
and 5197. It generates disposable signing/control secrets in ignored
`.tmp/session-security-prototype`; normal teardown removes the two private files.
If a process is forcibly killed, remove only `private.pem` and `control-secret`
in that exact directory. On Windows, file mode 0600 does not prove an ACL policy;
use a private development workspace and synthetic data only.

The harness uses the installed MSAL public client and its supported popup bridge.
Fake identity responses are signed locally; the fixture checks the actual SDK's
PKCE exchange. PHP independently validates signatures, claims, a single-use fresh
proof, host/realm/principal-bound sessions, deadlines, origin and CSRF.
The locked file store is a disposable fixture, never a proposed production store.

All browser network requests are intercepted. Only three `.session.test` clinic/
admin origins and the fake identity origin are allowed; other destinations abort.
Their HTTPS responses come from loopback fixtures. No TLS check is disabled, but
there is no real HTTPS connection, certificate validation or hosted acceptance.
WebKit's intercepted requests omit Cookie headers despite cookies being present
in its native jar. Its fixture transport explicitly forwards eligible native-jar
cookies. Edge's intercepted Cookie headers are forwarded directly. Native WebKit
cookie attributes/isolation are tested; real Safari HTTPS dispatch is unverified.

The popup bridge timeout is deliberately four seconds for synthetic tests, not
a proposed production MFA time limit. Memory-only tokens disappear on reload or
in a new tab; a new interactive ceremony is deliberately required. The prototype
does not provide a persistent refresh-token fallback, provider logout, privileged
recent-authentication flows or real MFA. Existing application auth is unchanged.

Reports and traces are kept out of Git. The prototype disables Playwright traces;
its current diagnostics use classifications and key names. Initial development
route errors included generated synthetic request headers in ignored private logs;
do not share those logs as sanitized output. Its UI and fixtures must never be
pointed at real identities.

See [the dated results and remaining gates](../../documentation/SESSION_SECURITY_PROTOTYPE_2026-11-02.md)
and [the browser follow-up](../../documentation/SESSION_SECURITY_BROWSER_FOLLOWUP_2026-11-02.md)
and [the implementation plan](../../documentation/SESSION_SECURITY_REVIEW_2026-11-02.md).

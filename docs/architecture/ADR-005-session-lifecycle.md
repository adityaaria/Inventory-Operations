# ADR-005 — Bounded native session lifecycle

Date: 2026-10-07. Status: implemented engineering decision, authorized by the user's session enhancement request. Timeout values are configurable engineering defaults, not trainer-prescribed requirements.

Requirements: AUTH-01/02, USR-01, API-01, ERR-01, ARCH-01/02, TEST-01/03. Official brief p. 5 requires session authentication, active-account checks, regeneration after login, and logout blocking protected URLs. Hardening supplements those mandatory behaviors.

Use native PHP file sessions with a separate pure SessionPolicy. Default idle 1800 seconds, absolute 28800 seconds, rotation 900 seconds. Check deadlines inclusively on each authenticated request, irrespective of garbage collection. Preserve original login timestamp through rotation and privilege refresh. Missing/invalid metadata, unsupported roles and invalid user IDs fail closed. Existing sessions without the new metadata/credential version must sign in again once.

Strict mode rejects unknown attacker-chosen IDs; sessions travel only through cookies, never rewritten URLs. Cookies are host-only, Path=/, browser-lifetime, HttpOnly and SameSite=Lax. Production defaults to Secure and refuses an explicit insecure override. Behind trusted TLS termination set SESSION_COOKIE_SECURE=true; do not trust arbitrary forwarded headers. Production must actually use HTTPS.

Login, logout and role changes rotate the ID and CSRF token; routine rotation retains CSRF so already-open forms can still submit. Login/logout clear all previous session data. Before rotating, persist an empty old session with `session_regenerate_id(false)`; the old file is an unauthenticated tombstone, never a forwarding alias to the new authenticated ID. This retains PHP's file-lock serialization without leaving duplicate authenticated IDs. A request already queued with the previous ID is denied rather than granted a grace period. At a rotation boundary concurrent tabs/requests can therefore receive 401 and need to reload/sign in; do not automatically replay mutations. No database transaction can be rolled back solely by a different request logging out after the first request was authorized.

Login binds a SHA-256 digest of the stored password hash to the session. AuthGuard reads current user state/role/hash on requests: missing/inactive users or changed credentials revoke access; privilege changes refresh session claims and rotate identity/CSRF without extending absolute duration. No passwords/raw password hashes/session IDs are added to logs or browser storage. Deactivation or changing the credential hash revokes each affected session on its next request; logout ends the current session only.

Set no-store/private headers for dynamic HTML, redirects, errors, JSON and CSV. Complete session writes and release the session lock before sending/streaming the response. Unauthenticated page navigation redirects to login; API remains JSON 401, Fetch dialogs receive 401 and show a sign-in link; protected POST authenticates before CSRF validation, so expiry is not mislabeled as a token failure. No automatic POST retry.

Compose stores session files in a dedicated session-data volume writable by www-data, preserving sessions through app container recreation. MySQL data and test data are independent. PHP garbage collection removes expired files/tombstones according to deployment settings; application deadlines enforce access even before cleanup. Never expose or publish the volume. Removing it deliberately invalidates sessions.

Alternatives: browser token storage/JWT would broaden the browser authentication contract and revocation complexity; Redis is deferred by AGENTS; a shared MySQL session repository would require schema, locking/cleanup and operational decisions without a current multi-host requirement. Keep bounded native storage for this single-host Docker application. A multi-host rollout requires a reviewed shared session store, retention/cleanup monitoring and a production PHP HTTP runtime/TLS deployment; this ADR does not claim high availability.

Verification is recorded in `docs/testing/session-hardening-2026-10-07.md`.

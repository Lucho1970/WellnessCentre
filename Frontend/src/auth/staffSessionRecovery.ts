import { BrowserAuthErrorCodes, InteractionRequiredAuthError } from '@azure/msal-browser';

const recoveryKey = 'wellness.staff.sessionRecoveryStartedAt';
const recoveryWindowMs = 2 * 60 * 1000;

/** A silent refresh can time out instead of returning InteractionRequiredAuthError. */
export function needsInteractiveStaffAuth(error: unknown): boolean {
  if (error instanceof InteractionRequiredAuthError) return true;
  if (!error || typeof error !== 'object' || !('errorCode' in error)) return false;
  return error.errorCode === BrowserAuthErrorCodes.timedOut;
}

/** Persist across the redirect, so a failed return cannot start an auth loop. */
export function claimStaffSessionRecovery(now = Date.now(), storage: Storage = window.sessionStorage): boolean {
  try {
    const previous = Number(storage.getItem(recoveryKey));
    if (Number.isFinite(previous) && previous > 0 && now - previous >= 0 && now - previous < recoveryWindowMs) return false;
    storage.setItem(recoveryKey, String(now));
    return true;
  } catch {
    // Storage may be disabled; the in-memory redirect guard still prevents parallel redirects.
    return true;
  }
}

export function clearStaffSessionRecovery(storage: Storage = window.sessionStorage): void {
  try { storage.removeItem(recoveryKey); } catch { /* Browser storage is optional. */ }
}

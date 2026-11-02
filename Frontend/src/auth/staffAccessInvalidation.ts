// A local UI signal only. The API remains the authority for every operation.
const listeners = new Set<() => void>();
const accountDenials = new Set([
  'account_inactive', 'membership_required', 'staff_not_provisioned',
  'role_assignment_mismatch', 'membership_binding_mismatch', 'staff_identity_required',
  'missing_scope', 'pilot_access_denied',
]);

export function reportStaffAccessFailure(status: number, code: unknown): void {
  if (status !== 401 && !(status === 403 && typeof code === 'string' && accountDenials.has(code))) return;
  for (const listener of listeners) listener();
}

export function onStaffAccessInvalidated(listener: () => void): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

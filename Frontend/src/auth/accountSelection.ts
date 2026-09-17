import type { AccountInfo } from '@azure/msal-browser';

// Browser account entries can be shared by multiple MSAL applications on one origin.
// This is UI selection only; the API still validates tokens and permissions.
export function selectAccount(accounts: AccountInfo[], preferred: AccountInfo | null | undefined, tenant: string, hosts: string[]): AccountInfo | null {
  const matches = (account: AccountInfo) => Boolean(tenant) && account.tenantId === tenant
    && hosts.includes(account.environment.toLowerCase());
  if (preferred && matches(preferred)) return preferred;
  return accounts.find(matches) ?? null;
}

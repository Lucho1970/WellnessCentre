import { createContext, useCallback, useContext, useMemo, type PropsWithChildren } from 'react';
import { InteractionRequiredAuthError, PublicClientApplication, type AccountInfo } from '@azure/msal-browser';
import { MsalProvider, useIsAuthenticated, useMsal } from '@azure/msal-react';

const tenantId = import.meta.env.VITE_ENTRA_TENANT_ID ?? '';
const spaClientId = import.meta.env.VITE_ENTRA_SPA_CLIENT_ID ?? '';
const apiClientId = import.meta.env.VITE_ENTRA_API_CLIENT_ID ?? '';
const configured = Boolean(tenantId && spaClientId && apiClientId);
const apiScopes = apiClientId ? [`api://${apiClientId}/access_as_user`] : [];

export const msalInstance = new PublicClientApplication({
  auth: { clientId: spaClientId || '00000000-0000-0000-0000-000000000000', authority: `https://login.microsoftonline.com/${tenantId || 'organizations'}`, redirectUri: window.location.origin, postLogoutRedirectUri: window.location.origin },
  cache: { cacheLocation: 'sessionStorage' },
});

type StaffAuthValue = { account: AccountInfo | null; configured: boolean; isAuthenticated: boolean; signIn: () => Promise<void>; signOut: () => Promise<void>; getAccessToken: () => Promise<string> };
const StaffAuthContext = createContext<StaffAuthValue | null>(null);

function StaffAuthBridge({ children }: PropsWithChildren) {
  const { instance, accounts } = useMsal();
  const isAuthenticated = useIsAuthenticated();
  const account = instance.getActiveAccount() ?? accounts[0] ?? null;
  const signIn = useCallback(async () => { if (!configured) throw new Error('Microsoft Entra staff sign-in is not configured.'); const result = await instance.loginPopup({ scopes: apiScopes, prompt: 'select_account' }); instance.setActiveAccount(result.account); }, [instance]);
  const signOut = useCallback(async () => { await instance.logoutPopup({ account: account ?? undefined, postLogoutRedirectUri: window.location.origin }); }, [account, instance]);
  const getAccessToken = useCallback(async () => { if (!account) throw new Error('Staff sign-in is required.'); try { return (await instance.acquireTokenSilent({ account, scopes: apiScopes })).accessToken; } catch (error) { if (error instanceof InteractionRequiredAuthError) return (await instance.acquireTokenPopup({ account, scopes: apiScopes })).accessToken; throw error; } }, [account, instance]);
  const value = useMemo(() => ({ account, configured, isAuthenticated, signIn, signOut, getAccessToken }), [account, getAccessToken, isAuthenticated, signIn, signOut]);
  return <StaffAuthContext.Provider value={value}>{children}</StaffAuthContext.Provider>;
}

export function StaffAuthProvider({ children }: PropsWithChildren) { return <MsalProvider instance={msalInstance}><StaffAuthBridge>{children}</StaffAuthBridge></MsalProvider>; }
export function useStaffAuth() { const value = useContext(StaffAuthContext); if (!value) throw new Error('useStaffAuth must be used within StaffAuthProvider.'); return value; }

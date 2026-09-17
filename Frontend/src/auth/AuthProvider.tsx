import { createContext, useCallback, useContext, useMemo, type PropsWithChildren } from 'react';
import { InteractionRequiredAuthError, PublicClientApplication, type AccountInfo } from '@azure/msal-browser';
import { MsalProvider, useMsal } from '@azure/msal-react';
import { selectAccount } from './accountSelection';

const tenantId = import.meta.env.VITE_ENTRA_TENANT_ID ?? '';
const spaClientId = import.meta.env.VITE_ENTRA_SPA_CLIENT_ID ?? '';
const apiClientId = import.meta.env.VITE_ENTRA_API_CLIENT_ID ?? '';
const configured = Boolean(tenantId && spaClientId && apiClientId);
const apiScopes = apiClientId ? [`api://${apiClientId}/access_as_user`] : [];
const portalRoot = new URL(import.meta.env.BASE_URL, window.location.origin).href;
const redirect = new URL(import.meta.env.VITE_ENTRA_REDIRECT_URI || portalRoot);
if (redirect.origin !== window.location.origin || !redirect.pathname.startsWith(import.meta.env.BASE_URL) || redirect.search || redirect.hash) {
  throw new Error('Staff authentication must return to this portal, without query or fragment.');
}
const redirectUri = redirect.href;

export const msalInstance = new PublicClientApplication({
  auth: { clientId: spaClientId || '00000000-0000-0000-0000-000000000000', authority: `https://login.microsoftonline.com/${tenantId || 'organizations'}`, redirectUri, postLogoutRedirectUri: portalRoot },
  cache: { cacheLocation: 'sessionStorage' },
});

export function selectStaffAccount(preferred = msalInstance.getActiveAccount()) {
  return selectAccount(msalInstance.getAllAccounts(), preferred, tenantId, ['login.windows.net', 'login.microsoftonline.com', 'login.microsoft.com', 'sts.windows.net']);
}

type StaffAuthValue = { account: AccountInfo | null; configured: boolean; isAuthenticated: boolean; signIn: () => Promise<void>; signOut: () => Promise<void>; getAccessToken: () => Promise<string> };
const StaffAuthContext = createContext<StaffAuthValue | null>(null);

function StaffAuthBridge({ children }: PropsWithChildren) {
  const { instance, accounts } = useMsal();
  const account = useMemo(() => selectStaffAccount(instance.getActiveAccount()), [instance, accounts]);
  const isAuthenticated = Boolean(account);
  const signIn = useCallback(async () => { if (!configured) throw new Error('Microsoft Entra staff sign-in is not configured.'); await instance.loginRedirect({ scopes: apiScopes, prompt: 'select_account' }); }, [instance]);
  const signOut = useCallback(async () => { await instance.logoutRedirect({ account: account ?? undefined, postLogoutRedirectUri: portalRoot }); }, [account, instance]);
  const getAccessToken = useCallback(async () => { if (!account) throw new Error('Staff sign-in is required.'); try { return (await instance.acquireTokenSilent({ account, scopes: apiScopes })).accessToken; } catch (error) { if (error instanceof InteractionRequiredAuthError) { await instance.acquireTokenRedirect({ account, scopes: apiScopes }); throw new Error('Redirecting to Microsoft Entra for authorization.'); } throw error; } }, [account, instance]);
  const value = useMemo(() => ({ account, configured, isAuthenticated, signIn, signOut, getAccessToken }), [account, getAccessToken, isAuthenticated, signIn, signOut]);
  return <StaffAuthContext.Provider value={value}>{children}</StaffAuthContext.Provider>;
}

export function StaffAuthProvider({ children }: PropsWithChildren) { return <MsalProvider instance={msalInstance}><StaffAuthBridge>{children}</StaffAuthBridge></MsalProvider>; }
export function useStaffAuth() { const value = useContext(StaffAuthContext); if (!value) throw new Error('useStaffAuth must be used within StaffAuthProvider.'); return value; }

import { privateSessionStorage } from './privateSessionStorage';
import { createContext, useCallback, useContext, useMemo, useState, useSyncExternalStore, type PropsWithChildren } from 'react';
import { InteractionStatus, PublicClientApplication, type AccountInfo } from '@azure/msal-browser';
import { MsalProvider, useMsal } from '@azure/msal-react';
import { useTranslation } from 'react-i18next';
import { selectAccount } from './accountSelection';
import i18n from '../i18n';
import { claimStaffSessionRecovery, clearStaffSessionRecovery, needsInteractiveStaffAuth } from './staffSessionRecovery';
import { practitionerIdentityEnabled, practitionerLoginParameters, practitionerSocialSignInEnabled, type PractitionerProvider } from './practitionerIdentity';
import { eraseLegacyIdentityCache, serverSessionsEnabled } from './serverSessionMode';
import { configureServerSession, readServerSessionToken, sessionSnapshot, signInServerSession, signOutServerSession, subscribeSession } from './serverSession';
import { ServerSessionNotice } from './ServerSessionNotice';

export const externalStaff = __APP_SURFACE__ !== 'application-admin' && practitionerIdentityEnabled && privateSessionStorage.getItem('wellness.staff.provider') === 'external';
const tenantId = (externalStaff ? import.meta.env.VITE_STAFF_EXTERNAL_TENANT_ID : import.meta.env.VITE_ENTRA_TENANT_ID) ?? '';
const spaClientId = (externalStaff ? import.meta.env.VITE_STAFF_EXTERNAL_SPA_CLIENT_ID : import.meta.env.VITE_ENTRA_SPA_CLIENT_ID) ?? '';
const apiClientId = (externalStaff ? import.meta.env.VITE_STAFF_EXTERNAL_API_CLIENT_ID : import.meta.env.VITE_ENTRA_API_CLIENT_ID) ?? '';
const externalHost = `${import.meta.env.VITE_STAFF_EXTERNAL_SUBDOMAIN ?? ''}.ciamlogin.com`;
const configured = Boolean(tenantId && spaClientId && apiClientId && (!externalStaff || /^[a-z0-9][a-z0-9-]{0,62}$/.test(import.meta.env.VITE_STAFF_EXTERNAL_SUBDOMAIN ?? '')));
const apiScopes = apiClientId ? [`api://${apiClientId}/${externalStaff ? 'access_as_staff' : 'access_as_user'}`] : [];
const portalRoot = new URL(import.meta.env.BASE_URL, window.location.origin).href;
const redirect = new URL(__APP_SURFACE__ === 'application-admin' ? portalRoot : externalStaff ? `${import.meta.env.BASE_URL}staff/external` : import.meta.env.VITE_ENTRA_REDIRECT_URI || portalRoot,window.location.origin);
if (redirect.origin !== window.location.origin || !redirect.pathname.startsWith(import.meta.env.BASE_URL) || redirect.search || redirect.hash) {
  throw new Error(i18n.t('Staff authentication must return to this portal, without query or fragment.'));
}
const redirectUri = redirect.href;
eraseLegacyIdentityCache([import.meta.env.VITE_ENTRA_SPA_CLIENT_ID ?? '',import.meta.env.VITE_STAFF_EXTERNAL_SPA_CLIENT_ID ?? '',import.meta.env.VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID ?? '']);

export const msalInstance = new PublicClientApplication({
  auth: { clientId: spaClientId || '00000000-0000-0000-0000-000000000000', authority: externalStaff ? `https://${externalHost}/${tenantId}` : `https://login.microsoftonline.com/${tenantId || 'organizations'}`, knownAuthorities: externalStaff ? [externalHost] : [], redirectUri, postLogoutRedirectUri: portalRoot },
  cache: { cacheLocation: serverSessionsEnabled() ? 'memoryStorage' : 'sessionStorage' },
});
// Both identity modules may be imported for account-menu links. Only the
// document's selected bootstrap may select the session's realm and client.
export function configureStaffServerSession() {
  configureServerSession(__APP_SURFACE__ === 'application-admin' ? 'central' : externalStaff ? 'practitioner' : 'workforce', { instance: msalInstance, scopes: apiScopes, redirectUri });
}

export function selectStaffAccount(preferred = msalInstance.getActiveAccount()) {
  return selectAccount(msalInstance.getAllAccounts(), preferred, tenantId, externalStaff ? [externalHost,`${tenantId}.ciamlogin.com`] : ['login.windows.net', 'login.microsoftonline.com', 'login.microsoft.com', 'sts.windows.net']);
}

type StaffAuthValue = { account: AccountInfo | null; configured: boolean; isAuthenticated: boolean; sessionExpired: boolean; signInError: string | null; signIn: (provider?: PractitionerProvider) => Promise<void>; signOut: () => Promise<void>; getAccessToken: () => Promise<string> };
const StaffAuthContext = createContext<StaffAuthValue | null>(null);
let sessionRecoveryRedirect: Promise<void> | null = null;

function StaffAuthBridge({ children, redirectFailed = false }: PropsWithChildren<{ redirectFailed?: boolean }>) {
  const { t } = useTranslation();
  const { instance, accounts, inProgress } = useMsal();
  const session = useSyncExternalStore(subscribeSession,sessionSnapshot);
  const selected = useMemo(() => redirectFailed && !serverSessionsEnabled() ? null : selectStaffAccount(instance.getActiveAccount()), [instance, accounts, redirectFailed]);
  const account = serverSessionsEnabled() && session.state !== 'active' ? null : selected;
  const isAuthenticated = Boolean(account);
  const [sessionExpired, setSessionExpired] = useState(false);
  const signIn = useCallback(async (provider?: PractitionerProvider) => {
    if (!configured) throw new Error(t('Microsoft Entra staff sign-in is not configured.'));
    if (provider && (!externalStaff || !practitionerSocialSignInEnabled)) throw new Error(t('Practitioner sign-in is not configured. Contact the clinic.'));
    if (serverSessionsEnabled()) { await signInServerSession(externalStaff ? practitionerLoginParameters(provider) : {}); return; }
    if (inProgress !== InteractionStatus.None) throw new Error(t('A sign-in is already in progress. Complete it or refresh this page, then try again.'));
    if (sessionRecoveryRedirect) return sessionRecoveryRedirect;
    clearStaffSessionRecovery();
    if (!externalStaff) { await instance.loginRedirect({ scopes: apiScopes, prompt: 'select_account' }); return; }
    const previous = instance.getActiveAccount();
    instance.setActiveAccount(null);
    try { await instance.loginRedirect({ scopes: apiScopes, ...practitionerLoginParameters(provider) }); }
    catch (error) { instance.setActiveAccount(previous); throw error; }
  }, [instance, inProgress, t]);
  const signOut = useCallback(async () => { if(serverSessionsEnabled()){await signOutServerSession();return;} await instance.logoutRedirect({ account: account ?? undefined, postLogoutRedirectUri: portalRoot }); }, [account, instance]);
  const getAccessToken = useCallback(async () => {
    if(serverSessionsEnabled())return readServerSessionToken();
    if (!account) throw new Error(t('Staff sign-in is required.'));
    try {
      const token = (await instance.acquireTokenSilent({ account, scopes: apiScopes })).accessToken;
      if (!sessionRecoveryRedirect) {
        clearStaffSessionRecovery();
        setSessionExpired(false);
      }
      return token;
    } catch (error) {
      if (!needsInteractiveStaffAuth(error)) throw error;
      setSessionExpired(true);
      if (!sessionRecoveryRedirect && claimStaffSessionRecovery()) {
        if (externalStaff) instance.setActiveAccount(null);
        sessionRecoveryRedirect = instance.loginRedirect({ scopes: apiScopes, ...(externalStaff ? practitionerLoginParameters() : { prompt: 'select_account' }) });
      }
      try { await sessionRecoveryRedirect; } catch { /* The sign-in button remains available. */ }
      finally { sessionRecoveryRedirect = null; }
      throw new Error(t(externalStaff ? 'Your practitioner session has expired. Sign in again with your original account.' : 'Your staff session has expired. Sign in with Microsoft to continue.'), { cause: error });
    }
  }, [account, instance, t]);
  const signInError = redirectFailed && !serverSessionsEnabled() ? t('Sign-in could not be completed. Try again or choose another sign-in method.') : null;
  const expired = serverSessionsEnabled() ? session.state === 'ended' : sessionExpired;
  const value = useMemo(() => ({ account, configured, isAuthenticated, sessionExpired:expired, signInError, signIn, signOut, getAccessToken }), [account, getAccessToken, isAuthenticated, expired, signInError, signIn, signOut]);
  return <StaffAuthContext.Provider value={value}><ServerSessionNotice/>{children}</StaffAuthContext.Provider>;
}

export function StaffAuthProvider({ children, redirectFailed }: PropsWithChildren<{ redirectFailed?: boolean }>) { return <MsalProvider instance={msalInstance}><StaffAuthBridge redirectFailed={redirectFailed}>{children}</StaffAuthBridge></MsalProvider>; }
export function useStaffAuth() { const value = useContext(StaffAuthContext); if (!value) throw new Error('useStaffAuth must be used within StaffAuthProvider.'); return value; }

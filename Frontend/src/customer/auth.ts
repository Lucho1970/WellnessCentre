import { InteractionRequiredAuthError, PublicClientApplication } from '@azure/msal-browser';
import { selectAccount } from '../auth/accountSelection';
import { freshCustomerLoginParameters, shouldClearCustomerAccountHint } from './providerRouting';

const tenant = import.meta.env.VITE_CUSTOMER_ENTRA_TENANT_ID ?? '';
const subdomain = import.meta.env.VITE_CUSTOMER_ENTRA_SUBDOMAIN ?? '';
const clientId = import.meta.env.VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID ?? '';
const apiId = import.meta.env.VITE_CUSTOMER_ENTRA_API_CLIENT_ID ?? '';
export const customerConfigured = Boolean(tenant && subdomain && clientId && apiId);
const root = new URL(import.meta.env.BASE_URL, window.location.origin);
export const customerHome = new URL('client', root).href;
export const customerScopes = [`api://${apiId}/access_as_client`];
const customerHosts = [`${subdomain}.ciamlogin.com`, `${tenant}.ciamlogin.com`];
export const customerInstance = new PublicClientApplication({
  auth: {
    clientId: clientId || '00000000-0000-0000-0000-000000000000',
    authority: `https://${subdomain || 'unconfigured'}.ciamlogin.com/${tenant || 'unconfigured'}`,
    knownAuthorities: customerConfigured ? customerHosts : ['unconfigured.ciamlogin.com'],
    redirectUri: new URL('client/auth/callback', root).href,
    postLogoutRedirectUri: customerHome,
  },
  cache: { cacheLocation: 'sessionStorage' },
});

export function selectCustomerAccount(preferred = customerInstance.getActiveAccount()) {
  return selectAccount(customerInstance.getAllAccounts(), preferred, tenant, customerHosts);
}

export async function customerToken() {
  const account = selectCustomerAccount();
  if (!account) throw new Error('Please sign in to your client account.');
  try {
    return (await customerInstance.acquireTokenSilent({ account, scopes: customerScopes })).accessToken;
  } catch (error) {
    if (error instanceof InteractionRequiredAuthError) throw new Error('Your session needs authorization. Please sign in again.');
    throw new Error('Unable to authorize your client session. Please try signing in again.');
  }
}

export async function customerSignIn() {
  const { beginCustomerLogin } = await import('./session');
  const nonce = await beginCustomerLogin();
  const account = selectCustomerAccount();
  const clearCachedAccountHint = Boolean(nonce && shouldClearCustomerAccountHint(account));

  // A cached External ID account can carry an opaque login_hint (and an opaque
  // username fallback). External ID rejects either hint when domain_hint is also
  // present. Keep the account in MSAL's cache, but do not make it the active
  // account for this provider-routed authorization request.
  if (clearCachedAccountHint) customerInstance.setActiveAccount(null);

  try {
    await customerInstance.loginRedirect({
      scopes: customerScopes,
      prompt: nonce ? 'login' : 'select_account',
      ...(nonce ? freshCustomerLoginParameters(nonce, account) : {}),
    });
  } catch (error) {
    // If navigation could not start, restore the existing local session display.
    if (clearCachedAccountHint && account) customerInstance.setActiveAccount(account);
    throw error;
  }
}

export async function customerSignOut() {
  const { endCustomerSession } = await import('./session');
  await endCustomerSession();
  const account = selectCustomerAccount();
  if (!account) return;
  await customerInstance.logoutRedirect({ account, postLogoutRedirectUri: customerHome });
}

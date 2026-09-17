import { InteractionRequiredAuthError, PublicClientApplication } from '@azure/msal-browser';

const tenant = import.meta.env.VITE_CUSTOMER_ENTRA_TENANT_ID ?? '';
const subdomain = import.meta.env.VITE_CUSTOMER_ENTRA_SUBDOMAIN ?? '';
const clientId = import.meta.env.VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID ?? '';
const apiId = import.meta.env.VITE_CUSTOMER_ENTRA_API_CLIENT_ID ?? '';
export const customerConfigured = Boolean(tenant && subdomain && clientId && apiId);
const root = new URL(import.meta.env.BASE_URL, window.location.origin);
export const customerHome = new URL('client', root).href;
export const customerScopes = [`api://${apiId}/access_as_client`];
export const customerInstance = new PublicClientApplication({
  auth: {
    clientId: clientId || '00000000-0000-0000-0000-000000000000',
    authority: `https://${subdomain || 'unconfigured'}.ciamlogin.com/${tenant || 'unconfigured'}`,
    knownAuthorities: [`${subdomain || 'unconfigured'}.ciamlogin.com`],
    redirectUri: new URL('client/auth/callback', root).href,
    postLogoutRedirectUri: customerHome,
  },
  cache: { cacheLocation: 'sessionStorage' },
});

export async function customerToken() {
  const account = customerInstance.getActiveAccount();
  if (!account) throw new Error('Please sign in to your client account.');
  try {
    return (await customerInstance.acquireTokenSilent({ account, scopes: customerScopes })).accessToken;
  } catch (error) {
    if (error instanceof InteractionRequiredAuthError) throw new Error('Your session needs authorization. Please sign in again.');
    throw new Error('Unable to authorize your client session. Please try signing in again.');
  }
}

export async function customerSignIn() {
  await customerInstance.loginRedirect({ scopes: customerScopes, prompt: 'select_account' });
}

export async function customerSignOut() {
  await customerInstance.logoutRedirect({ account: customerInstance.getActiveAccount(), postLogoutRedirectUri: customerHome });
}

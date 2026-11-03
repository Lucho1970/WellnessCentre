import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProviders } from '../shared/AppProviders';
import { StaffAuthProvider, msalInstance, selectStaffAccount } from '../auth/AuthProvider';
import { PortalApp } from './PortalApp';
import { clearStaffRedirectResponse } from '../auth/staffRedirectCleanup';
import { serverSessionsEnabled } from '../auth/serverSessionMode';
import { prepareServerSession } from '../auth/serverSession';

export async function bootstrap() {
  // MSAL may load the portal redirect URI in a hidden iframe for silent token
  // renewal. The parent frame reads the response hash; rendering the portal in
  // the iframe could start another token request and make renewal time out.
  if (window.self !== window.top) return;
  await msalInstance.initialize();
  let result = null;
  let redirectFailed = false;
  const callbackUrl = window.location.href;
  try { if(serverSessionsEnabled()){(await import('../auth/AuthProvider')).configureStaffServerSession();await prepareServerSession();}else result = await msalInstance.handleRedirectPromise(); }
  catch { redirectFailed = true; } // Render a safe recovery state; never expose raw provider errors.
  finally { clearStaffRedirectResponse(callbackUrl); }
  const account = redirectFailed ? null : selectStaffAccount(result?.account ?? msalInstance.getActiveAccount());
  msalInstance.setActiveAccount(account);
  createRoot(document.getElementById('root')!).render(<React.StrictMode><AppProviders><StaffAuthProvider redirectFailed={redirectFailed}><PortalApp /></StaffAuthProvider></AppProviders></React.StrictMode>);
}

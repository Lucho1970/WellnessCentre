import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProviders } from '../shared/AppProviders';
import { ClientApp } from './ClientApp';
import { customerConfigured, customerHome, customerInstance, selectCustomerAccount } from './auth';
import { finishCustomerLogin } from './session';

export async function bootstrap() {
  if (window.location.pathname.endsWith('/client/invite')) {
    const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
    if (token && /^[a-f0-9]{64}$/.test(token)) sessionStorage.setItem('wellness.customer.invitation', token);
    window.history.replaceState(null, '', customerHome);
  }
  if (window.parent !== window && window.location.pathname.endsWith('/client/auth/callback')) {
    const { broadcastResponseToMainFrame } = await import('@azure/msal-browser/redirect-bridge');
    await broadcastResponseToMainFrame();
    return;
  }
  let error = '';
  if (customerConfigured) {
    try {
      await customerInstance.initialize();
      const result = await customerInstance.handleRedirectPromise({ navigateToLoginRequestUrl: false });
      const account = selectCustomerAccount(result?.account ?? customerInstance.getActiveAccount());
      customerInstance.setActiveAccount(account);
      if (result && account) await finishCustomerLogin(result.idToken, result.accessToken);
    } catch {
      error = 'Client sign-in could not be completed. Please try again. If it continues, contact the clinic.';
    }
  }
  // Remove authorization response/query data before mounting any application UI.
  if (window.location.pathname.endsWith('/client/auth/callback')) window.history.replaceState(null, '', customerHome);
  createRoot(document.getElementById('root')!).render(<React.StrictMode><AppProviders><ClientApp initialError={error} /></AppProviders></React.StrictMode>);
}

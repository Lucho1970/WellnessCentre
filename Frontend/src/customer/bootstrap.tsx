import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProviders } from '../shared/AppProviders';
import { ClientApp } from './ClientApp';
import { customerConfigured, customerHome, customerInstance, selectCustomerAccount } from './auth';

export async function bootstrap() {
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
    } catch {
      error = 'Client sign-in could not be completed. Please try again. If it continues, contact the clinic.';
    }
  }
  // Remove authorization response/query data before mounting any application UI.
  if (window.location.pathname.endsWith('/client/auth/callback')) window.history.replaceState(null, '', customerHome);
  createRoot(document.getElementById('root')!).render(<React.StrictMode><AppProviders><ClientApp initialError={error} /></AppProviders></React.StrictMode>);
}

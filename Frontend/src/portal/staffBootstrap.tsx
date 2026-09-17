import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProviders } from '../shared/AppProviders';
import { StaffAuthProvider, msalInstance, selectStaffAccount } from '../auth/AuthProvider';
import { PortalApp } from './PortalApp';

export async function bootstrap() {
  await msalInstance.initialize();
  const result = await msalInstance.handleRedirectPromise();
  const account = selectStaffAccount(result?.account ?? msalInstance.getActiveAccount());
  msalInstance.setActiveAccount(account);
  createRoot(document.getElementById('root')!).render(<React.StrictMode><AppProviders><StaffAuthProvider><PortalApp /></StaffAuthProvider></AppProviders></React.StrictMode>);
}

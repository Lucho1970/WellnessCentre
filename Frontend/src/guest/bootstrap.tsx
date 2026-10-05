import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProviders } from '../shared/AppProviders';
import { GuestPortal } from './GuestPortal';

export async function bootstrap() {
  createRoot(document.getElementById('root')!).render(<React.StrictMode><AppProviders><GuestPortal/></AppProviders></React.StrictMode>);
}

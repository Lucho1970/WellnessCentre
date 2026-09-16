import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProviders } from '../shared/AppProviders';
import App from '../App';
export function bootstrap() {
  createRoot(document.getElementById('root')!).render(<React.StrictMode><AppProviders><App /></AppProviders></React.StrictMode>);
}

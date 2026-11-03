import './styles.css';
import { loadRuntimeConfig } from './shared/runtimeConfig';
import { returnPopupResponse } from './auth/serverSessionMode';

// Compile-time selection keeps workforce authentication out of the public build.
const start = returnPopupResponse().then(async returned => { if (returned) return; await loadRuntimeConfig(); const module = await (__APP_SURFACE__ === 'portal' ? import('./portal/bootstrap') : import('./public/bootstrap')); await module.bootstrap(); });
void start.catch(() => {
  const root = document.getElementById('root');
  if (root) root.textContent = 'Unable to start the application. Please reload the page. If this continues, contact the clinic.';
});

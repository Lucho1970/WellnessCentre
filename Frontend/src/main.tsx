import './styles.css';
import { loadRuntimeConfig } from './shared/runtimeConfig';

// Compile-time selection keeps workforce authentication out of the public build.
const start = loadRuntimeConfig().then(() => __APP_SURFACE__ === 'portal' ? import('./portal/bootstrap') : import('./public/bootstrap'));
void start.then(module => module.bootstrap()).catch(() => {
  const root = document.getElementById('root');
  if (root) root.textContent = 'Unable to start the application. Please reload the page. If this continues, contact the clinic.';
});

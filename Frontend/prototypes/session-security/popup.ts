import { broadcastResponseToMainFrame } from '@azure/msal-browser/redirect-bridge';
import { hosts } from './protocol';

if (location.protocol !== 'https:' || !hosts.includes(location.hostname)) throw new Error('Synthetic HTTPS origin required.');
void broadcastResponseToMainFrame().catch(() => { document.body.textContent = 'The synthetic sign-in return failed. Close this popup and try again.'; });

import { publicUrl } from '../shared/urls';
import { customerConfigured, customerInstance, selectCustomerAccount } from './auth';

// Read-only account display hint for our public site. No token acquisition, account
// identifiers, emails, permissions, or clinical data ever cross the origin boundary.
export async function bootstrap() {
  if (window.parent === window) return;
  const ready = customerConfigured ? customerInstance.initialize().then(() => true, () => false) : Promise.resolve(false);
  window.addEventListener('message', async event => {
    if (event.source !== window.parent || event.origin !== publicUrl.origin
      || event.data?.type !== 'wellness:account-request' || typeof event.data.nonce !== 'string' || event.data.nonce.length > 100) return;
    let initials = '';
    try {
      if (await ready) {
        const account = selectCustomerAccount();
        const expiry = account?.idTokenClaims?.exp;
        if (account && typeof expiry === 'number' && expiry * 1000 > Date.now()) {
          initials = (account.name || 'Client').split(/\s+/).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0]).join('').toUpperCase();
        }
      }
    } catch { /* Storage may be blocked: public navigation must still work. */ }
    window.parent.postMessage({ type: 'wellness:account-display', nonce: event.data.nonce, initials }, publicUrl.origin);
  });
  // Dynamic bootstrap can finish after the iframe's load event.
  window.parent.postMessage({ type: 'wellness:account-ready' }, publicUrl.origin);
}

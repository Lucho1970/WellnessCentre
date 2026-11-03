// Test-only composition of production session code, real MSAL, real API routes.
import { returnPopupResponse, loadServerSessionMode } from '../../src/auth/serverSessionMode';
if(!await returnPopupResponse()){
  await loadServerSessionMode();
  const {bootstrap}=await import('./workspace');bootstrap();
}

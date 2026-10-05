export async function bootstrap() {
  if (window.location.pathname.endsWith('/staff/invitation') || window.location.pathname.endsWith('/staff/external')) {
    if (import.meta.env.VITE_STAFF_INVITATIONS_ENABLED === 'true') sessionStorage.setItem('wellness.staff.provider','external');
    const token=new URLSearchParams(window.location.hash.slice(1)).get('token');
    if (token && /^[a-f0-9]{64}$/.test(token)) {sessionStorage.setItem('wellness.staff.invitation',token);history.replaceState(null,'',window.location.pathname);}
  } else if (window.location.pathname.endsWith('/staff/login')) sessionStorage.removeItem('wellness.staff.provider');
  const root = import.meta.env.BASE_URL.replace(/\/$/, '');
  if (/^\/practitioners\/[a-z0-9-]+$/.test(window.location.pathname)
    || /^\/services\/[a-z0-9-]+\/book$/.test(window.location.pathname)
    || window.location.pathname === `${root}/availability`) {
    await (await import('../guest/bootstrap')).bootstrap();
    return;
  }
  if (window.location.pathname === `${root}/` || window.location.pathname === root) {
    // Existing workforce Entra registrations return to the portal root. Only
    // an actual authorization response should mount MSAL here; ordinary root
    // visits remain guest browsing and never start staff authentication.
    const hash = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    const query = new URLSearchParams(window.location.search);
    const staffCallback = [hash, query].some(params => params.has('state') && (params.has('code') || params.has('error')));
    // Existing public-site staff bookmarks explicitly request a workforce
    // workspace. Keep those links working while an ordinary root visit stays
    // an anonymous guest entry point.
    const staffBookmark = /^[a-z]+$/.test(query.get('portal') ?? '');
    if (staffCallback || staffBookmark) { await (await import('./staffBootstrap')).bootstrap(); return; }
    await (await import('../guest/bootstrap')).bootstrap();
    return;
  }
  if (window.location.pathname === `${import.meta.env.BASE_URL}login`) {
    window.location.replace(`${import.meta.env.BASE_URL}client`);
    return;
  }
  if (window.location.pathname === `${import.meta.env.BASE_URL}client/session`) {
    await (await import('../customer/sessionBridge')).bootstrap();
    return;
  }
  const clientPath = `${import.meta.env.BASE_URL}client`;
  if (window.location.pathname === `${import.meta.env.BASE_URL}book` || window.location.pathname === clientPath || window.location.pathname.startsWith(`${clientPath}/`)) {
    await (await import('../customer/bootstrap')).bootstrap();
    return;
  }
  await (await import('./staffBootstrap')).bootstrap();
}

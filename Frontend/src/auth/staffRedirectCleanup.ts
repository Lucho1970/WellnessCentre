const responseKeys = [
  'code', 'state', 'session_state', 'client_info', 'error', 'error_description',
  'error_uri', 'correlation_id', 'client-request-id', 'cloud_instance_host_name',
  'cloud_graph_host_name', 'msgraph_host',
];

/** Called only after MSAL has consumed the response, including a failed response. */
export function clearStaffRedirectResponse(callbackUrl: string): void {
  // Never overwrite navigation which occurred while redirect processing was pending.
  if (window.location.href !== callbackUrl) return;
  const url = new URL(callbackUrl);
  let changed = false;
  for (const kind of ['search', 'hash'] as const) {
    const params = new URLSearchParams(url[kind].slice(1));
    if (!params.has('state') || !(params.has('code') || params.has('error'))) continue;
    for (const key of responseKeys) params.delete(key);
    url[kind] = params.toString();
    changed = true;
  }
  if (changed) window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
}

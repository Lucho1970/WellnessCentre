type CustomerAccountWithClaims = {
  idTokenClaims?: Record<string, unknown>;
} | null | undefined;

export type CustomerProviderHint = 'google';

/**
 * Map only trusted, signed ID-token provider claims to an allowlisted External ID hint.
 * Never forward an arbitrary claim value to the authorization endpoint.
 */
export function customerProviderHint(account: CustomerAccountWithClaims): CustomerProviderHint | null {
  const claim = account?.idTokenClaims?.idp;
  if (typeof claim !== 'string') return null;

  const normalized = claim.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/$/, '');
  return normalized === 'google' || normalized === 'google.com' || normalized === 'accounts.google.com'
    ? 'google'
    : null;
}

export function freshCustomerLoginParameters(nonce: string, account: CustomerAccountWithClaims) {
  const providerHint = customerProviderHint(account);
  return {
    nonce,
    maxAge: 0,
    // Explicit query parameter: this MSAL version does not serialize maxAge=0.
    extraQueryParameters: {
      max_age: '0',
      ...(providerHint ? { domain_hint: providerHint } : {}),
    },
    claims: JSON.stringify({ id_token: { auth_time: { essential: true } } }),
  };
}

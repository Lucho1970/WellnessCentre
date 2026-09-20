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

export function shouldClearCustomerAccountHint(account: CustomerAccountWithClaims) {
  return customerProviderHint(account) !== null;
}

export function freshCustomerLoginParameters(nonce: string, account: CustomerAccountWithClaims) {
  const providerHint = customerProviderHint(account);
  return {
    nonce,
    maxAge: 0,
    // Use MSAL's supported property instead of adding domain_hint as a raw query
    // parameter. MSAL can then suppress the cached opaque login_hint, because
    // External ID rejects domain_hint and an opaque login_hint used together.
    ...(providerHint ? { domainHint: providerHint } : {}),
    // Explicit query parameter: this MSAL version does not serialize maxAge=0.
    extraQueryParameters: {
      max_age: '0',
    },
    claims: JSON.stringify({ id_token: { auth_time: { essential: true } } }),
  };
}

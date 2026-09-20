type CachedCustomerAccount = object | null | undefined;

export function shouldClearCustomerAccountHint(account: CachedCustomerAccount) {
  return Boolean(account);
}

export function freshCustomerLoginParameters(nonce: string) {
  return {
    nonce,
    maxAge: 0,
    // Explicit query parameter: this MSAL version does not serialize maxAge=0.
    extraQueryParameters: {
      max_age: '0',
    },
    claims: JSON.stringify({ id_token: { auth_time: { essential: true } } }),
  };
}

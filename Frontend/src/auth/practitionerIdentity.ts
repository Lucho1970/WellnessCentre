export type PractitionerProvider = 'google' | 'microsoft';

export const practitionerIdentityEnabled = import.meta.env.VITE_STAFF_INVITATIONS_ENABLED === 'true'
  || import.meta.env.VITE_PRACTITIONER_APPLICATIONS_ENABLED === 'true'
  || import.meta.env.VITE_STAFF_EXTERNAL_AUTH_ENABLED === 'true';

// Enable only in builds whose dedicated practitioner user flow has both providers.
export const practitionerSocialSignInEnabled = practitionerIdentityEnabled
  && import.meta.env.VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED === 'true';

export function practitionerLoginParameters(provider?: PractitionerProvider) {
  if (provider !== undefined && provider !== 'google' && provider !== 'microsoft') {
    throw new Error('Unsupported practitioner sign-in provider.');
  }
  // External ID issuer acceleration: MSA is a custom OIDC provider with the
  // login.live.com issuer. These hints never change our API token trust.
  return { prompt: 'login', ...(provider ? { domainHint: provider === 'google' ? 'google' : 'login.live.com' } : {}) };
}

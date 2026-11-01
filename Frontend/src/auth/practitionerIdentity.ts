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
  // The live tenant rejects google/login.live.com acceleration (AADSTS90023/90072).
  // Open its supported provider picker for either choice; retain the staff authority
  // and scope, and never use a provider hint supplied through the page URL.
  return { prompt: 'login' };
}

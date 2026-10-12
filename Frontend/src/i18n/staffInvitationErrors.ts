// Preserve the invitation API's specific conflict reasons in the admin UI.
const messages: Record<string, [string, string]> = {
  email_already_exists: ['An account in this clinic already uses that email address. Review the existing account before creating or linking another practitioner.', 'Un compte de cette clinique utilise déjà cette adresse courriel. Examinez le compte existant avant de créer ou de lier un autre praticien.'],
  membership_exists: ['This account or sign-in already has a staff membership. Linking another sign-in requires a reviewed identity migration.', 'Ce compte ou cette identité possède déjà une affiliation au personnel. La liaison d’une autre identité nécessite une migration vérifiée.'],
  invitation_conflict: ['The invitation conflicts with an existing account or membership. Review the existing records before trying again.', 'L’invitation est en conflit avec un compte ou une affiliation existante. Examinez les dossiers existants avant de réessayer.'],
  invitation_unavailable: ['This invitation is expired, revoked or already accepted. Refresh the invitation list to check its status.', 'Cette invitation a expiré, a été révoquée ou a déjà été acceptée. Actualisez la liste pour vérifier son état.'],
  claim_required: ['This invitation has no pending signed-in claim to approve. Refresh the list and confirm the practitioner submitted their claim.', 'Cette invitation n’a aucune demande de connexion en attente à approuver. Actualisez la liste et confirmez que le praticien a soumis sa demande.'],
  inviter_inactive: ['The administrator who created this invitation is no longer authorized. An active Super Admin must issue a new invitation.', 'L’administrateur ayant créé cette invitation n’est plus autorisé. Un super administrateur actif doit émettre une nouvelle invitation.'],
  location_not_found: ['The selected clinic location is unavailable. Review the location before issuing or approving the invitation.', 'L’emplacement sélectionné de la clinique n’est pas disponible. Vérifiez-le avant d’émettre ou d’approuver l’invitation.'],
  identity_inactive: ['This sign-in identity is inactive. Review its access status before onboarding.', 'Cette identité de connexion est inactive. Vérifiez son accès avant l’intégration.'],
  account_binding_conflict: ['The selected existing staff account cannot be linked through this invitation. Its account and membership require review.', 'Le compte de personnel existant sélectionné ne peut pas être lié par cette invitation. Son compte et son affiliation doivent être examinés.'],
  practitioner_required: ['The selected existing account must already be an active practitioner.', 'Le compte existant sélectionné doit déjà être un praticien actif.'],
  role_conflict: ['The existing account has elevated roles and cannot be linked through a practitioner invitation. A separate migration is required.', 'Le compte existant a des rôles privilégiés et ne peut pas être lié par une invitation de praticien. Une migration distincte est nécessaire.'],
};
export default {
  en: Object.fromEntries(Object.entries(messages).map(([code, values]) => [`API error: ${code}`, values[0]])),
  fr: Object.fromEntries(Object.entries(messages).map(([code, values]) => [`API error: ${code}`, values[1]])),
};

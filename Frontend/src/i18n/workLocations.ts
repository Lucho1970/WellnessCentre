// Translations for private travel-origin settings and coverage results.
const pairs: Record<string, string> = {
  'Private work location': 'Lieu de départ privé',
  'Home address': 'Adresse du domicile',
  'Work location': 'Lieu de travail',
  'Same as home address': 'Même adresse que le domicile',
  'Optional unless your work location is the same as home.': 'Facultatif, sauf si votre lieu de travail est votre domicile.',
  'Choose where your workday normally starts. These addresses are private and are not shown to clients. Google processes the starting address to calculate On-Site coverage.': 'Choisissez votre lieu de départ habituel pour la journée de travail. Ces adresses sont privées et ne sont pas montrées aux clients. Google traite l’adresse de départ pour calculer la zone de service à domicile.',
  'Your saved home address will be used. Updating it also updates your work location.': 'Votre adresse de domicile enregistrée sera utilisée. Toute modification met aussi à jour votre lieu de travail.',
  'Work location saved. Previous coverage checks must be renewed.': 'Lieu de travail enregistré. Les vérifications précédentes de la zone de service doivent être renouvelées.',
  'Unable to load or save your work location.': 'Impossible de charger ou d’enregistrer votre lieu de travail.',
  'Save work location': 'Enregistrer le lieu de travail',
  'Reload saved location': 'Recharger le lieu enregistré',
  'Enter a complete Canadian address.': 'Entrez une adresse canadienne complète.',
  'These settings changed. Reload before saving.': 'Ces paramètres ont changé. Rechargez-les avant d’enregistrer.',
  'This address is outside the practitioner’s On-Site service area.': 'Cette adresse se trouve hors de la zone de service à domicile du praticien.',
  'Google validates your visit address and checks whether it is within the practitioner’s On-Site service area.': 'Google valide l’adresse de visite et vérifie si elle se trouve dans la zone de service à domicile du praticien.',
  'Your address is within the practitioner’s On-Site service area.': 'Votre adresse se trouve dans la zone de service à domicile du praticien.',
  'Google validates the visit address and calculates driving distance from the practitioner’s starting location, or the clinic base when no private work location is configured.': 'Google valide l’adresse de visite et calcule la distance routière depuis le lieu de départ du praticien, ou depuis la clinique si aucun lieu de travail privé n’est configuré.',
};
export default { en: Object.fromEntries(Object.keys(pairs).map(key => [key, key])), fr: pairs };

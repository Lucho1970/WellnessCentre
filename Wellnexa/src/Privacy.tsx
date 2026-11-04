import { site } from './config/site'

const ipcUrl = 'https://www.ipc.on.ca/en/health-individuals'

export function Privacy({ french }: { french: boolean }) {
  const otherLanguage = `/privacy?lang=${french ? 'en' : 'fr'}`
  const contact = <a href={`mailto:${site.privacyEmail}`}>{site.privacyEmail}</a>

  return <article className="privacy-page" aria-labelledby="privacy-title">
    <nav className="privacy-language" aria-label={french ? 'Langue de la déclaration' : 'Statement language'}>
      <a href="/privacy?lang=en" aria-current={!french ? 'page' : undefined}>English</a>
      <a href="/privacy?lang=fr" aria-current={french ? 'page' : undefined}>Français</a>
    </nav>
    <p className="eyebrow">{site.descriptor}</p>
    <h1 id="privacy-title">{french ? 'Déclaration de confidentialité' : 'Privacy statement'}</h1>
    <p>{french ? 'Entrée en vigueur : 4 novembre 2026' : 'Effective: November 4, 2026'}</p>
    {french ? <>
      <p>Cette déclaration explique comment les renseignements personnels et les renseignements personnels sur la santé sont traités lorsque vous consultez le site Wellnexa, communiquez avec nous ou utilisez le portail de la clinique. Elle ne remplace pas le consentement aux soins ni les avis propres à un praticien ou à un service.</p>

      <h2>Responsabilité et contact</h2>
      <p>{site.privacyCustodianNameFr} est responsable des renseignements soumis sur le site Wellnexa et des renseignements personnels sur la santé conservés dans le portail de la clinique. Les praticiens indépendants sont responsables des notes et des autres dossiers qu’ils conservent séparément, hors du portail. Nous vous aiderons à adresser votre demande à la bonne personne responsable.</p>
      <p>Pour toute question, demande d’accès ou de rectification, directive de confidentialité ou plainte, communiquez avec la personne responsable de la confidentialité à {contact}. Évitez d’envoyer des détails cliniques par courriel ordinaire; nous vous indiquerons une méthode appropriée pour les transmettre.</p>

      <h2>Renseignements recueillis</h2>
      <p>Selon les fonctions utilisées, nous recueillons les coordonnées et les renseignements d’identité nécessaires à l’ouverture d’un compte; les choix de services, rendez-vous, horaires et lieux; les réponses aux formulaires et les directives de consentement ou de confidentialité; les communications; ainsi que les renseignements de facturation, d’état des paiements et de reçus. Les demandes de renseignements des praticiens peuvent inclure leurs coordonnées, leur profession et leur message. Les journaux techniques et d’accès servent à la sécurité et au fonctionnement du service. Nous demandons uniquement les renseignements nécessaires à la fin visée.</p>

      <h2>Utilisation et communication</h2>
      <p>Nous utilisons ces renseignements pour gérer l’inscription, les rendez-vous, les services demandés, les communications opérationnelles, les reçus, la sécurité, les demandes liées aux dossiers et nos obligations légales. L’accès est limité selon le rôle, la clinique et la relation de soins. Les renseignements peuvent être communiqués au praticien ou au personnel autorisé qui en a besoin pour le service, à un fournisseur de services agissant pour nous selon des conditions appropriées, ou lorsque la loi l’autorise ou l’exige. Les renseignements sur la santé ne sont pas utilisés pour le marketing sans autorisation distincte lorsque celle-ci est requise.</p>

      <h2>Services externes</h2>
      <p>Le portail fait appel à des services d’hébergement et d’authentification. Si vous choisissez la connexion Google ou Microsoft, le fournisseur d’identité traite les renseignements nécessaires à la connexion selon ses propres pratiques. Les avis opérationnels peuvent passer par un fournisseur de courriel et, lorsque l’option est activée, de SMS. Si vous demandez une recherche d’adresse ou un trajet pour une visite à domicile, les données nécessaires peuvent être transmises à un service cartographique. Nous ne transmettons pas automatiquement vos réponses cliniques dans les messages de rappel. Communiquez avec nous pour connaître les fournisseurs et les lieux de traitement pertinents à votre utilisation.</p>

      <h2>Vos choix et vos droits</h2>
      <p>Vous pouvez nous poser des questions sur l’utilisation de vos renseignements, demander l’accès ou la rectification d’un dossier, ou nous faire part d’une restriction ou d’un retrait de consentement lorsque la loi le permet. Nous vérifierons votre identité et votre autorité avant de divulguer des renseignements, y compris lorsqu’une personne agit pour quelqu’un d’autre. Une rectification ne supprime pas nécessairement le dossier original, et un retrait n’efface pas les utilisations ou communications antérieures autorisées. Des obligations légales ou professionnelles peuvent exiger la conservation de certains dossiers.</p>

      <h2>Conservation et protection</h2>
      <p>Les dossiers sont conservés selon leur nature, la profession concernée et les exigences applicables; la fermeture d’un compte ne signifie pas leur suppression immédiate. Nous utilisons des mesures de protection administratives et techniques, notamment des contrôles d’accès et des journaux, et nous examinons les incidents signalés. Aucun service en ligne ne peut garantir l’absence de risque.</p>

      <h2>Plaintes</h2>
      <p>Adressez d’abord votre plainte à {contact}. Vous pouvez également communiquer avec le <a href={ipcUrl}>Commissaire à l’information et à la protection de la vie privée de l’Ontario</a> au sujet des renseignements personnels sur la santé soumis à la loi ontarienne.</p>

      <h2>Pendant les essais</h2>
      <p>Les comptes d’essai doivent utiliser des renseignements fictifs. N’inscrivez pas de vrais renseignements médicaux, de numéros de police d’assurance ou de données de paiement tant que la clinique n’a pas autorisé leur utilisation.</p>
    </> : <>
      <p>This statement explains how personal information and personal health information are handled when you visit the Wellnexa website, contact us, or use the clinic portal. It does not replace consent to care or notices specific to a practitioner or service.</p>

      <h2>Responsibility and contact</h2>
      <p>{site.privacyCustodianNameEn} is responsible for information submitted through the Wellnexa website and personal health information stored in the clinic portal. Independent practitioners are responsible for notes and other records they keep separately, outside the portal. We will help direct a request to the appropriate responsible party.</p>
      <p>For questions, access or correction requests, privacy instructions, or complaints, contact the privacy lead at {contact}. Please do not send clinical details in ordinary email; we will arrange an appropriate way to provide them.</p>

      <h2>Information we collect</h2>
      <p>Depending on the features you use, we collect contact and identity details needed for an account; service, appointment, schedule, and location choices; form answers and consent or privacy instructions; communications; and billing, payment status, and receipt details. Practitioner enquiries may include professional details, contact information, and a message. Technical and access logs support security and service operation. We ask for information needed for the stated purpose.</p>

      <h2>How we use and share information</h2>
      <p>We use this information to manage registration, appointments, requested services, operational notices, receipts, record requests, security, and legal duties. Access is limited by role, clinic, and care relationship. Information may be shared with the treating practitioner or authorized staff who need it for the service, with service providers acting for us under appropriate terms, or as permitted or required by law. We do not use health information for marketing without separate authorization where required.</p>

      <h2>Outside services</h2>
      <p>The portal uses hosting and sign-in services. If you choose Google or Microsoft sign-in, that identity provider processes sign-in details under its own practices. Operational notices may use an email provider and, when enabled, an SMS provider. If you request address search or a route for an on-site visit, necessary details may be sent to a maps provider. We do not automatically put clinical form answers into reminder messages. Contact us for current providers and processing locations relevant to your use.</p>

      <h2>Your choices and rights</h2>
      <p>You may ask how your information is used, request access to or correction of a record, or tell us about a restriction or withdrawal of consent where the law permits. We verify identity and authority before disclosing information, including when someone acts for another person. A correction does not necessarily erase the original record, and withdrawal does not undo earlier authorized uses or disclosures. Legal and professional duties may require some records to be retained.</p>

      <h2>Retention and safeguards</h2>
      <p>Records are kept according to their type, the relevant profession, and applicable requirements; closing an account does not immediately delete them. We use administrative and technical safeguards, including access controls and logs, and review reported incidents. No online service can promise zero risk.</p>

      <h2>Complaints</h2>
      <p>Send a complaint to {contact}. You may also contact the <a href={ipcUrl}>Information and Privacy Commissioner of Ontario</a> about personal health information governed by Ontario law.</p>

      <h2>During testing</h2>
      <p>Test accounts must use fictional information. Do not enter real medical information, insurance policy numbers, or payment details until the clinic has authorized their use.</p>
    </>}
    <p><a href={otherLanguage}>{french ? 'Read this statement in English' : 'Lire cette déclaration en français'}</a></p>
  </article>
}

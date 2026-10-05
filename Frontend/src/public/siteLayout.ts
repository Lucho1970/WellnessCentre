import layout from '../../content/site-layout.json';

export type PublicSection = {
  id: string;
  kind: 'markdown' | 'services' | 'practitioners' | 'team' | 'contact';
  contentKey?: string;
  presentation?: 'split' | 'faq';
  navLabel?: string;
  surface: 'plain' | 'soft';
};

export const publicSections = layout.sections as PublicSection[];
export const publicNavigation = publicSections.filter(section => section.navLabel);

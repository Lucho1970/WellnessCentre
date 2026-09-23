export type Workspace = 'admin' | 'practitioner';
export type PortalPage = 'dashboard' | 'appointments' | 'clients' | 'calendar' | 'business' | 'practitioners' | 'staff' | 'team' | 'widgets' | 'notifications' | 'locations' | 'rooms' | 'services' | 'profile';
const operations = ['super_admin', 'clinic_admin', 'reception', 'accountant'];
const bookingRoles = ['super_admin', 'clinic_admin', 'reception'];
export function workspacesFor(roles: string[]): Workspace[] {
  return [...(roles.some(role => operations.includes(role)) ? ['admin' as const] : []), ...(roles.includes('practitioner') ? ['practitioner' as const] : [])];
}
export function pagesFor(roles: string[], workspace: Workspace): PortalPage[] {
  if (!workspacesFor(roles).includes(workspace)) return [];
  if (workspace === 'practitioner') return ['dashboard', 'appointments', 'calendar', 'profile'];
  return ['dashboard', ...(roles.some(role => bookingRoles.includes(role)) ? ['appointments' as const, 'clients' as const] : []),
    ...(roles.some(role => ['super_admin', 'clinic_admin'].includes(role)) ? ['notifications' as const] : []),
    ...(roles.includes('super_admin') ? ['calendar', 'practitioners', 'staff', 'team', 'widgets', 'locations', 'rooms', 'services', 'business'] as PortalPage[] : []), 'profile'];
}
const slugs: Record<PortalPage, string> = { dashboard: '', appointments: 'appointments', clients: 'clients', calendar: 'availability', business: 'settings', practitioners: 'practitioners', staff: 'users', team: 'team', widgets: 'dashboard-widgets', notifications: 'notifications', locations: 'locations', rooms: 'rooms', services: 'services', profile: 'profile' };
export function pagePath(workspace: Workspace, page: PortalPage) {
  const slug = workspace === 'practitioner' && page === 'appointments' ? 'schedule' : slugs[page];
  return `/${workspace}${slug ? `/${slug}` : ''}`;
}
export function pageAt(pathname: string, workspace: Workspace): PortalPage | undefined {
  const normalized = pathname.replace(/\/$/, '');
  return (Object.keys(slugs) as PortalPage[]).find(page => pagePath(workspace, page) === normalized);
}
export function legacyPage(value: string | null): PortalPage | undefined {
  return value && Object.hasOwn(slugs, value) ? value as PortalPage : undefined;
}

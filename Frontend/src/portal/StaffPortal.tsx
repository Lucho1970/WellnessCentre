import { lazy, Suspense, useEffect, useState, type ReactNode } from "react";
import { Link, Navigate, useLocation } from 'react-router-dom';
import { legacyPage, pageAt, pagePath, pagesFor, workspacesFor, type PortalPage, type Workspace } from './access';
import { useStaffAuth } from '../auth/AuthProvider';
import { useTranslation } from 'react-i18next';
import {
  Box,
  Alert,
  Button,
  Divider,
  Drawer,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Paper,
  Stack,
  Typography,
  useMediaQuery,
  useTheme,
} from "@mui/material";
import {
  Building2,
  CalendarDays,
  LayoutDashboard,
  MapPin,
  Menu,
  PanelsTopLeft,
  Stethoscope,
  UserRound,
  DoorOpen,
  HandHeart,
  Users,
  CalendarRange,
  X,
} from "lucide-react";
const BusinessSettings = lazy(() => import('../admin/BusinessSettings').then(module => ({ default: module.BusinessSettings })));
const PractitionerAdmin = lazy(() => import('../admin/PractitionerAdmin').then(module => ({ default: module.PractitionerAdmin })));
const LocationAdmin = lazy(() => import('../admin/LocationAdmin').then(module => ({ default: module.LocationAdmin })));
const ProfileSettings = lazy(() => import('../profile/ProfileSettings').then(module => ({ default: module.ProfileSettings })));
const RoomAdmin = lazy(() => import('../admin/RoomAdmin').then(module => ({ default: module.RoomAdmin })));
const ServiceAdmin = lazy(() => import('../admin/ServiceAdmin').then(module => ({ default: module.ServiceAdmin })));
const CatalogueSettings = lazy(() => import('../admin/CatalogueSettings').then(module => ({ default: module.CatalogueSettings })));
const StaffAdmin = lazy(() => import('../admin/StaffAdmin').then(module => ({ default: module.StaffAdmin })));
const TeamAdmin = lazy(() => import('../admin/TeamAdmin').then(module => ({ default: module.TeamAdmin })));
const AvailabilityAdmin = lazy(() => import('../scheduling/AvailabilityAdmin').then(module => ({ default: module.AvailabilityAdmin })));
const ClientManagement = lazy(() => import('../clients/ClientManagement').then(module => ({ default: module.ClientManagement })));
const StaffAppointments = lazy(() => import('../booking/StaffAppointments').then(module => ({ default: module.StaffAppointments })));
const Dashboard = lazy(() => import('../dashboard/Dashboard').then(module => ({ default: module.Dashboard })));
const DashboardWidgetAdmin = lazy(() => import('../admin/DashboardWidgetAdmin').then(module => ({ default: module.DashboardWidgetAdmin })));

type NavigationItem = {
  id: PortalPage;
  label: string;
  description: string;
  icon: ReactNode;
};

const navigation: NavigationItem[] = [
  { id: "dashboard", label: "Dashboard", description: "Today at a glance", icon: <LayoutDashboard size={20} /> },
  { id: "appointments", label: "Appointments", description: "Bookings and scheduled visits", icon: <CalendarDays size={20} /> },
  { id: "clients", label: "Clients", description: "Contact details and client records", icon: <Users size={20} /> },
  { id: "calendar", label: "Availability", description: "Working hours and schedules", icon: <CalendarRange size={20} /> },
  {
    id: "locations",
    label: "Locations",
    description: "Addresses and booking access",
    icon: <MapPin size={20} />,
  },
  { id: "rooms", label: "Rooms", description: "Spaces and turnaround time", icon: <DoorOpen size={20} /> },
  { id: "services", label: "Services", description: "Care, pricing, and booking rules", icon: <HandHeart size={20} /> },
  { id: "staff", label: "Staff access", description: "Roles and account status", icon: <Users size={20} /> },
  { id: "team", label: "Public team", description: "Published staff profiles", icon: <Users size={20} /> },
  { id: "widgets", label: "Dashboard widgets", description: "Upload, version, and publish dashboard cards", icon: <PanelsTopLeft size={20} /> },
  {
    id: "profile",
    label: "My profile",
    description: "Identity and account preferences",
    icon: <UserRound size={20} />,
  },
  {
    id: "practitioners",
    label: "Practitioners",
    description: "Staff access and profiles",
    icon: <Stethoscope size={20} />,
  },
  {
    id: "business",
    label: "Business settings",
    description: "Clinic identity and defaults",
    icon: <Building2 size={20} />,
  },
];

export function StaffPortal({ roles, permissions = [] }: { roles: string[]; permissions?: string[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up("md"));
  const [drawerOpen, setDrawerOpen] = useState(false);
  const location = useLocation();
  const { account } = useStaffAuth();
  const workspaces = workspacesFor(roles);
  const preferenceKey = `wellness.workspace.${account?.homeAccountId ?? 'staff'}`;
  let remembered: string | null = null;
  try { remembered = sessionStorage.getItem(preferenceKey); } catch { /* Storage may be disabled. */ }
  const defaultWorkspace = workspaces.find(item => item === remembered) ?? workspaces[0];
  const workspace = location.pathname.split('/')[1] as Workspace;
  const allowedPages = pagesFor(roles, workspace);
  const allowedNavigation = navigation.filter(item => allowedPages.includes(item.id));
  const page = pageAt(location.pathname, workspace);
  useEffect(() => { setDrawerOpen(false); }, [location.pathname]);
  useEffect(() => {
    if (workspacesFor(roles).includes(workspace)) {
      try { sessionStorage.setItem(preferenceKey, workspace); } catch { /* Preference only, never authority. */ }
    }
  }, [roles, workspace, preferenceKey]);
  if (!defaultWorkspace) return <Alert severity="warning">{t('This account has no available staff workspace. Please contact the clinic administrator.')}</Alert>;
  if (['/', '/login', '/staff/login', '/profile'].includes(location.pathname)) {
    const requested = location.pathname === '/profile' ? 'profile' : legacyPage(new URLSearchParams(location.search).get('portal')) ?? 'dashboard';
    return <Navigate replace to={pagePath(defaultWorkspace, requested)} />;
  }
  if (!page || !allowedPages.includes(page)) return <Paper variant="outlined" sx={{ p: 4 }}><Alert severity="warning">{t(page ? 'You do not have permission to access this page.' : 'This portal page was not found.')}</Alert><Button component={Link} to={pagePath(defaultWorkspace, 'dashboard')} sx={{ mt: 2 }}>{t('Return to your workspace')}</Button></Paper>;
  const current = allowedNavigation.find(item => item.id === page)!;
  const navigationList = (
    <Box sx={{ width: 280, p: 2 }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" px={1} py={1.5}>
        <Box>
          <Typography variant="overline" color="primary.main" fontWeight={800}>{t(workspace === 'practitioner' ? 'Practitioner workspace' : 'Operations workspace')}</Typography>
          <Typography variant="h6">{t('Portal menu')}</Typography>
        </Box>
        {!desktop && <IconButton aria-label={t('Close portal menu')} onClick={() => setDrawerOpen(false)}><X size={20} /></IconButton>}
      </Stack>
      <Divider sx={{ mb: 1.5 }} />
      {workspaces.length > 1 && <Stack spacing={1} mb={2} aria-label={t('Switch workspace')}>{workspaces.map(item => <Button key={item} component={Link} to={pagePath(item, 'dashboard')} variant={workspace === item ? 'contained' : 'outlined'}>{t(item === 'admin' ? 'Operations' : 'Practitioner')}</Button>)}</Stack>}
      <List aria-label={t('Staff portal navigation')}>
        {allowedNavigation.map((item) => (
          <ListItemButton component={Link} to={pagePath(workspace, item.id)} aria-current={page === item.id ? 'page' : undefined} key={item.id} selected={page === item.id} sx={{ borderRadius: 2, mb: 0.75, alignItems: "flex-start" }}>
            <ListItemIcon sx={{ minWidth: 40, mt: 0.4, color: page === item.id ? "primary.main" : "text.secondary" }}>{item.icon}</ListItemIcon>
            <ListItemText primary={t(item.label)} secondary={t(item.description)} primaryTypographyProps={{ fontWeight: page === item.id ? 750 : 600 }} />
          </ListItemButton>
        ))}
      </List>
    </Box>
  );

  return (
    <Box sx={{ display: "flex", gap: 3, alignItems: "flex-start" }}>
      {desktop ? <Paper variant="outlined" component="nav" sx={{ flex: "0 0 280px", position: "sticky", top: 88, maxHeight: 'calc(100dvh - 112px)', overflowY: 'auto' }}>{navigationList}</Paper> : (
        <Drawer open={drawerOpen} onClose={() => setDrawerOpen(false)}>{navigationList}</Drawer>
      )}
      <Box sx={{ minWidth: 0, flex: 1 }}>
        <Stack direction="row" spacing={1.5} alignItems="center" mb={3}>
          {!desktop && <IconButton aria-label={t('Open portal menu')} onClick={() => setDrawerOpen(true)} sx={{ border: "1px solid", borderColor: "divider" }}><Menu /></IconButton>}
          <Box>
            <Typography variant="h4" component="h1">{t(current.label)}</Typography>
            <Typography color="text.secondary">{t(current.description)}</Typography>
          </Box>
        </Stack>
        <Suspense fallback={<Typography role="status">{t('Loading workspace…')}</Typography>}>
        {page === "dashboard" && <Dashboard workspace={workspace} />}
        {page === "clients" && <ClientManagement canMerge={roles.includes('super_admin')} />}
        {page === "appointments" && <StaffAppointments canManageFees={roles.some(role => ['super_admin', 'clinic_admin'].includes(role))} practitionerMode={workspace === 'practitioner'} canScheduleOthers={roles.some(role => ['super_admin', 'clinic_admin', 'reception'].includes(role)) || permissions.includes('schedule_for_other_practitioners')} canBook={(workspace === 'admin' && roles.some(role => ['super_admin', 'clinic_admin', 'reception'].includes(role))) || (workspace === 'practitioner' && roles.includes('practitioner'))} />}
        {page === "practitioners" && <PractitionerAdmin />}
        {page === "locations" && <LocationAdmin />}
        {page === "rooms" && <RoomAdmin />}
        {page === "services" && <ServiceAdmin />}
        {page === "business" && <BusinessSettings />}
        {page === "business" && <CatalogueSettings />}
        {page === "staff" && <StaffAdmin />}
        {page === "team" && <TeamAdmin />}
        {page === "widgets" && <DashboardWidgetAdmin />}
        {page === "calendar" && <AvailabilityAdmin practitionerMode={workspace === 'practitioner'} />}
        {page === "profile" && <ProfileSettings />}
        </Suspense>
      </Box>
    </Box>
  );
}

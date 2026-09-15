import { useEffect, useMemo, useState, type ReactNode } from "react";
import {
  Box,
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
  Stethoscope,
  UserRound,
  DoorOpen,
  HandHeart,
  Users,
  CalendarRange,
  X,
} from "lucide-react";
import { BusinessSettings } from "../admin/BusinessSettings";
import { PractitionerAdmin } from "../admin/PractitionerAdmin";
import { LocationAdmin } from "../admin/LocationAdmin";
import { ProfileSettings } from "../profile/ProfileSettings";
import { RoomAdmin } from "../admin/RoomAdmin";
import { RoomCapabilities } from "../admin/RoomCapabilities";
import { ServiceAdmin } from "../admin/ServiceAdmin";
import { ServiceAssignments } from "../admin/ServiceAssignments";
import { CatalogueSettings } from "../admin/CatalogueSettings";
import { StaffAdmin } from "../admin/StaffAdmin";
import { AvailabilityAdmin } from "../scheduling/AvailabilityAdmin";
import { ScheduleExceptions } from "../scheduling/ScheduleExceptions";
import { ClientManagement } from "../clients/ClientManagement";

type PortalPage = "dashboard" | "clients" | "calendar" | "business" | "practitioners" | "staff" | "locations" | "rooms" | "services" | "profile";

type NavigationItem = {
  id: PortalPage;
  label: string;
  description: string;
  icon: ReactNode;
  superAdminOnly?: boolean;
  roles?: string[];
};

const navigation: NavigationItem[] = [
  { id: "dashboard", label: "Dashboard", description: "Today at a glance", icon: <LayoutDashboard size={20} /> },
  { id: "clients", label: "Clients", description: "Contact details and client records", icon: <Users size={20} />, roles: ['super_admin', 'clinic_admin', 'reception'] },
  { id: "calendar", label: "Availability", description: "Working hours and schedules", icon: <CalendarRange size={20} />, superAdminOnly: true },
  {
    id: "locations",
    label: "Locations",
    description: "Addresses and booking access",
    icon: <MapPin size={20} />,
    superAdminOnly: true,
  },
  { id: "rooms", label: "Rooms", description: "Spaces and turnaround time", icon: <DoorOpen size={20} />, superAdminOnly: true },
  { id: "services", label: "Services", description: "Care, pricing, and booking rules", icon: <HandHeart size={20} />, superAdminOnly: true },
  { id: "staff", label: "Staff access", description: "Roles and account status", icon: <Users size={20} />, superAdminOnly: true },
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
    superAdminOnly: true,
  },
  {
    id: "business",
    label: "Business settings",
    description: "Clinic identity and defaults",
    icon: <Building2 size={20} />,
    superAdminOnly: true,
  },
];

function pageFromUrl(allowedPages: PortalPage[]): PortalPage {
  const requested = new URLSearchParams(window.location.search).get("portal") as PortalPage | null;
  return requested && allowedPages.includes(requested) ? requested : "dashboard";
}

function Dashboard() {
  return (
    <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 } }}><CalendarDays size={36} color="#176b62"/><Typography variant="h4" mt={2}>Your workspace is ready.</Typography><Typography color="text.secondary" mt={1} maxWidth={680}>Clinic catalogue and staff administration are configured here. Live schedules, appointment totals, room utilization, balances, and waitlist metrics will appear when Phase 4 connects the scheduling engine—no demonstration data is shown.</Typography></Paper>
  );
}

export function StaffPortal({ roles }: { roles: string[] }) {
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up("md"));
  const [drawerOpen, setDrawerOpen] = useState(false);
  const allowedNavigation = useMemo(
    () => navigation.filter((item) => (!item.superAdminOnly || roles.includes("super_admin")) && (!item.roles || item.roles.some(role => roles.includes(role)))),
    [roles],
  );
  const allowedPages = useMemo(() => allowedNavigation.map((item) => item.id), [allowedNavigation]);
  const [page, setPage] = useState<PortalPage>(() => pageFromUrl(allowedPages));

  useEffect(() => {
    if (!allowedPages.includes(page)) setPage("dashboard");
  }, [allowedPages, page]);

  useEffect(()=>{const navigate=(event:Event)=>{const requested=(event as CustomEvent<string>).detail as PortalPage;if(allowedPages.includes(requested))setPage(requested);};window.addEventListener('portal-navigate',navigate);return()=>window.removeEventListener('portal-navigate',navigate);},[allowedPages]);

  const selectPage = (nextPage: PortalPage) => {
    setPage(nextPage);
    setDrawerOpen(false);
    const url = new URL(window.location.href);
    if (nextPage === "dashboard") url.searchParams.delete("portal");
    else url.searchParams.set("portal", nextPage);
    window.history.replaceState({}, "", `${url.pathname}${url.search}#portal`);
  };

  const current = allowedNavigation.find((item) => item.id === page) ?? allowedNavigation[0];
  const navigationList = (
    <Box sx={{ width: 280, p: 2 }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" px={1} py={1.5}>
        <Box>
          <Typography variant="overline" color="primary.main" fontWeight={800}>Staff workspace</Typography>
          <Typography variant="h6">Portal menu</Typography>
        </Box>
        {!desktop && <IconButton aria-label="Close portal menu" onClick={() => setDrawerOpen(false)}><X size={20} /></IconButton>}
      </Stack>
      <Divider sx={{ mb: 1.5 }} />
      <List aria-label="Staff portal navigation">
        {allowedNavigation.map((item) => (
          <ListItemButton key={item.id} selected={page === item.id} onClick={() => selectPage(item.id)} sx={{ borderRadius: 2, mb: 0.75, alignItems: "flex-start" }}>
            <ListItemIcon sx={{ minWidth: 40, mt: 0.4, color: page === item.id ? "primary.main" : "text.secondary" }}>{item.icon}</ListItemIcon>
            <ListItemText primary={item.label} secondary={item.description} primaryTypographyProps={{ fontWeight: page === item.id ? 750 : 600 }} />
          </ListItemButton>
        ))}
      </List>
    </Box>
  );

  return (
    <Box sx={{ display: "flex", gap: 3, alignItems: "flex-start" }}>
      {desktop ? <Paper variant="outlined" component="nav" sx={{ flex: "0 0 280px", position: "sticky", top: 88 }}>{navigationList}</Paper> : (
        <Drawer open={drawerOpen} onClose={() => setDrawerOpen(false)}>{navigationList}</Drawer>
      )}
      <Box component="main" sx={{ minWidth: 0, flex: 1 }}>
        <Stack direction="row" spacing={1.5} alignItems="center" mb={3}>
          {!desktop && <IconButton aria-label="Open portal menu" onClick={() => setDrawerOpen(true)} sx={{ border: "1px solid", borderColor: "divider" }}><Menu /></IconButton>}
          <Box>
            <Typography variant="h4" component="h2">{current.label}</Typography>
            <Typography color="text.secondary">{current.description}</Typography>
          </Box>
        </Stack>
        {page === "dashboard" && <Dashboard />}
        {page === "clients" && <ClientManagement />}
        {page === "practitioners" && <PractitionerAdmin />}
        {page === "locations" && <LocationAdmin />}
        {page === "rooms" && <RoomAdmin />}
        {page === "rooms" && <Box mt={3}><RoomCapabilities /></Box>}
        {page === "services" && <ServiceAdmin />}
        {page === "services" && <Box mt={3}><ServiceAssignments /></Box>}
        {page === "business" && <BusinessSettings />}
        {page === "business" && <CatalogueSettings />}
        {page === "staff" && <StaffAdmin />}
        {page === "calendar" && <><AvailabilityAdmin /><ScheduleExceptions /></>}
        {page === "profile" && <ProfileSettings />}
      </Box>
    </Box>
  );
}

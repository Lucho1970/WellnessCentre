import { useEffect, useMemo, useState, type ReactNode } from "react";
import {
  Box,
  Button,
  Chip,
  Divider,
  Drawer,
  Grid,
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
  X,
} from "lucide-react";
import { BusinessSettings } from "../admin/BusinessSettings";
import { PractitionerAdmin } from "../admin/PractitionerAdmin";
import { LocationAdmin } from "../admin/LocationAdmin";
import { ProfileSettings } from "../profile/ProfileSettings";

type PortalPage = "dashboard" | "business" | "practitioners" | "locations" | "profile";

type NavigationItem = {
  id: PortalPage;
  label: string;
  description: string;
  icon: ReactNode;
  superAdminOnly?: boolean;
};

const navigation: NavigationItem[] = [
  {
    id: "locations",
    label: "Locations",
    description: "Addresses and booking access",
    icon: <MapPin size={20} />,
    superAdminOnly: true,
  },
  {
    id: "profile",
    label: "My profile",
    description: "Identity and account preferences",
    icon: <UserRound size={20} />,
  },
  {
    id: "dashboard",
    label: "Dashboard",
    description: "Today at a glance",
    icon: <LayoutDashboard size={20} />,
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
    <>
      <Grid container spacing={2.5}>
        {[
          ["Today’s appointments", "12", "2 awaiting confirmation"],
          ["Room utilization", "78%", "4 rooms active"],
          ["Outstanding balance", "$1,240", "8 open invoices"],
          ["Waitlist matches", "3", "Review expiring offers"],
        ].map(([label, value, note]) => (
          <Grid size={{ xs: 12, sm: 6, xl: 3 }} key={label}>
            <Paper variant="outlined" sx={{ p: 2.5, height: "100%" }}>
              <Typography color="text.secondary" variant="body2">{label}</Typography>
              <Typography variant="h4" mt={1}>{value}</Typography>
              <Typography color="primary.main" variant="body2">{note}</Typography>
            </Paper>
          </Grid>
        ))}
      </Grid>
      <Paper variant="outlined" sx={{ mt: 3, p: { xs: 2, md: 3 } }}>
        <Stack direction={{ xs: "column", sm: "row" }} justifyContent="space-between" alignItems={{ sm: "center" }} spacing={2}>
          <Box>
            <Typography variant="h6">Tuesday schedule</Typography>
            <Typography variant="body2" color="text.secondary">Conflicts, forms and appointment changes are surfaced here.</Typography>
          </Box>
          <Button variant="contained" startIcon={<CalendarDays size={18} />}>Create appointment</Button>
        </Stack>
        <Divider sx={{ my: 2 }} />
        {[
          "09:00 · Maya Chen · Therapeutic Massage · Room Cedar",
          "10:15 · Olivia Martin · Initial Nutrition Consult · Room Birch",
          "13:15 · James Patel · Naturopathic Follow-up · Room Cedar",
        ].map((appointment, index) => (
          <Stack key={appointment} direction={{ xs: "column", sm: "row" }} spacing={1.5} alignItems={{ sm: "center" }} sx={{ py: 1.2 }}>
            <Chip label={index === 1 ? "Forms due" : "Confirmed"} color={index === 1 ? "warning" : "success"} size="small" />
            <Typography>{appointment}</Typography>
          </Stack>
        ))}
      </Paper>
    </>
  );
}

export function StaffPortal({ roles }: { roles: string[] }) {
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up("md"));
  const [drawerOpen, setDrawerOpen] = useState(false);
  const allowedNavigation = useMemo(
    () => navigation.filter((item) => !item.superAdminOnly || roles.includes("super_admin")),
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
        {page === "practitioners" && <PractitionerAdmin />}
        {page === "locations" && <LocationAdmin />}
        {page === "business" && <BusinessSettings />}
        {page === "profile" && <ProfileSettings />}
      </Box>
    </Box>
  );
}

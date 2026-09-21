import {
  Alert,
  AppBar,
  Box,
  Button,
  Container,
  Paper,
  Stack,
  Toolbar,
  Typography,
} from "@mui/material";
import { Link, Route, Routes } from "react-router-dom";
import { StaffSignIn } from "../auth/StaffSignIn";
import { UserAccountMenu } from "../auth/UserAccountMenu";
import { useClinicConfig } from "../config/ClinicConfigProvider";
import { BrandLogo } from "../config/BrandLogo";
import { publicLink } from "../shared/urls";
import { StaffPortal } from "./StaffPortal";
import { useTranslation } from "react-i18next";
import { LanguageSwitcher } from "../i18n/LanguageSwitcher";

function ClientBookingInformation() {
  const { t } = useTranslation();
  return (
    <Paper
      variant="outlined"
      sx={{ p: { xs: 3, md: 5 }, maxWidth: 700, mx: "auto" }}
    >
      <Typography variant="h4" component="h1">
        {t("Book online")}
      </Typography>
      <Alert severity="info" sx={{ my: 3 }}>
        {t("Sign in to continue booking. No appointment has been requested or reserved.")}
      </Alert>
      <Typography>
        {t("Browse available times, then sign in to review and confirm. Selecting a time does not reserve it.")}
      </Typography>
      <Stack direction={{ xs: "column", sm: "row" }} spacing={2} mt={3}>
        <Button href={`${import.meta.env.BASE_URL}client`} variant="contained">
          {t("Sign in or create client account")}
        </Button>
        <Button href={publicLink("book")}>{t("Browse availability")}</Button>
      </Stack>
    </Paper>
  );
}
export function PortalApp() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  return (
    <>
      <Box component="a" href="#main-content" className="skip-link">
        {t("SkipToContent")}
      </Box>
      <AppBar
        position="sticky"
        color="inherit"
        elevation={0}
        sx={{ borderBottom: "1px solid", borderColor: "divider" }}
      >
        <Container maxWidth="xl">
          <Toolbar disableGutters sx={{ gap: 2, flexWrap: "wrap", py: 1 }}>
            <BrandLogo />
            <Typography
              component={Link}
              to="/"
              title={config.name}
              noWrap
              color="inherit"
              sx={{ textDecoration: "none", flexGrow: 1, minWidth: 0 }}
              fontWeight={800}
            >
              {config.name}
            </Typography>
            <Stack
              component="nav"
              aria-label={t("Portal navigation")}
              direction="row"
              spacing={1}
              flexWrap="wrap"
              sx={{
                width: { xs: "100%", sm: "auto" },
                justifyContent: { xs: "space-between", sm: "flex-start" },
              }}
            >
              <Button href={publicLink()} size="small">
                {t("Public website")}
              </Button>
              <Stack direction="row" spacing={0.5} alignItems="center" sx={{ flexShrink: 0 }}>
                <LanguageSwitcher />
                <UserAccountMenu />
              </Stack>
            </Stack>
          </Toolbar>
        </Container>
      </AppBar>
      <Container
        component="main"
        id="main-content"
        tabIndex={-1}
        maxWidth="xl"
        sx={{ py: { xs: 3, md: 5 }, minHeight: "85vh" }}
      >
        <Routes>
          <Route path="/client/*" element={<ClientBookingInformation />} />
          <Route
            path="*"
            element={
              <StaffSignIn>
                {(access) => <StaffPortal roles={access.roles} permissions={access.permissions} />}
              </StaffSignIn>
            }
          />
        </Routes>
      </Container>
    </>
  );
}

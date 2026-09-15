import { useEffect, useState } from "react";
import {
  Alert,
  AppBar,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Container,
  CircularProgress,
  Grid,
  MenuItem,
  Paper,
  Stack,
  Step,
  StepLabel,
  Stepper,
  TextField,
  Toolbar,
  Typography,
} from "@mui/material";
import {
  CalendarDays,
  Clock3,
  HeartPulse,
  MapPin,
  ShieldCheck,
  UsersRound,
} from "lucide-react";
import { StaffSignIn } from "./auth/StaffSignIn";
import { UserAccountMenu } from "./auth/UserAccountMenu";
import { useClinicConfig } from "./config/ClinicConfigProvider";
import { StaffPortal } from "./portal/StaffPortal";

const apiBaseUrl=import.meta.env.VITE_API_BASE_URL??"http://localhost:8080/api/v1";
type PublicLocation={id:number;name:string;city:string|null;province:string|null};
type PublicService={id:number;name:string;description:string|null;price_cents:number;durations:{id:number;minutes:number;price_cents:number}[]};
type PublicPractitioner={id:number;display_name:string;discipline:string;credentials:string|null};
type Slot={duration_option_id:number;starts_at:string;ends_at:string};

function Booking() {
  const [locations,setLocations]=useState<PublicLocation[]>([]),[services,setServices]=useState<PublicService[]>([]),[practitioners,setPractitioners]=useState<PublicPractitioner[]>([]),[slots,setSlots]=useState<Slot[]>([]);
  const [locationId,setLocationId]=useState(""),[serviceId,setServiceId]=useState(""),[practitionerId,setPractitionerId]=useState(""),[slot,setSlot]=useState<Slot|null>(null),[loading,setLoading]=useState(true),[error,setError]=useState("");
  const [confirmed, setConfirmed] = useState(false);
  const service=services.find(x=>x.id===Number(serviceId));const practitioner=practitioners.find(x=>x.id===Number(practitionerId));
  useEffect(()=>{void(async()=>{try{const[r1,r2]=await Promise.all([fetch(`${apiBaseUrl}/locations`),fetch(`${apiBaseUrl}/services`)]),[b1,b2]=await Promise.all([r1.json(),r2.json()]);if(!r1.ok||!r2.ok)throw new Error("Unable to load online booking.");setLocations(b1.data);setServices(b2.data);setLocationId(String(b1.data[0]?.id??""));setServiceId(String(b2.data[0]?.id??""));}catch(c){setError(c instanceof Error?c.message:"Unable to load online booking.");}finally{setLoading(false)}})()},[]);
  useEffect(()=>{if(!serviceId)return;void(async()=>{setPractitionerId("");setSlots([]);setSlot(null);const r=await fetch(`${apiBaseUrl}/practitioners?service_id=${serviceId}`),b=await r.json();if(r.ok){setPractitioners(b.data);setPractitionerId(String(b.data[0]?.id??""));}})()},[serviceId]);
  useEffect(()=>{if(!serviceId||!practitionerId||!locationId)return;void(async()=>{setSlot(null);const from=new Date().toISOString().slice(0,10),to=new Date(Date.now()+7*86400000).toISOString().slice(0,10),r=await fetch(`${apiBaseUrl}/availability?service_id=${serviceId}&practitioner_id=${practitionerId}&location_id=${locationId}&date_from=${from}&date_to=${to}`),b=await r.json();setSlots(r.ok?b.data.availability:[]);})()},[serviceId,practitionerId,locationId]);
  if (confirmed)
    return (
      <Paper sx={{ p: 4, textAlign: "center", maxWidth: 620, mx: "auto" }}>
        <HeartPulse size={42} color="#176b62" />
        <Typography variant="h4" mt={2}>
          Your appointment is requested
        </Typography>
        <Typography color="text.secondary" mt={1}>
          We’ve held {slot?new Date(slot.starts_at).toLocaleString():"your selected time"} with{" "}
          {practitioner?.display_name}. Client social sign-in remains an independent
          future flow.
        </Typography>
        <Stack direction="row" justifyContent="center" spacing={2} mt={3}>
          <Button variant="contained" disabled>
            Continue with Google
          </Button>
          <Button variant="outlined" disabled>
            View client portal
          </Button>
        </Stack>
      </Paper>
    );
  return (
    <Box id="booking" py={{ xs: 5, md: 9 }}>
      <Container maxWidth="lg">
        <Typography variant="overline" color="primary.main" fontWeight={700}>
          BOOK ONLINE
        </Typography>
        <Typography variant="h3" mb={1}>
          Find a time that fits your life.
        </Typography>
        <Typography color="text.secondary" mb={4}>
          Browse first. Sign in only when you’re ready to confirm.
        </Typography>
        <Stepper activeStep={slot ? 2 : 1} sx={{ mb: 4, maxWidth: 650 }}>
          <Step>
            <StepLabel>Choose care</StepLabel>
          </Step>
          <Step>
            <StepLabel>Select a time</StepLabel>
          </Step>
          <Step>
            <StepLabel>Confirm</StepLabel>
          </Step>
        </Stepper>
        {loading&&<Stack alignItems="center" py={4}><CircularProgress/></Stack>}{error&&<Alert severity="error" sx={{mb:3}}>{error}</Alert>}
        {!loading&&!error&&services.length===0&&<Alert severity="info">Online services have not been configured yet.</Alert>}
        {!loading&&!error&&services.length>0&&<Grid container spacing={3}>
          <Grid size={{ xs: 12, md: 5 }}>
            <Card variant="outlined">
              <CardContent>
                <Typography variant="h6" mb={2}>
                  1. Select care
                </Typography>
                <TextField select fullWidth label="Location" value={locationId} onChange={e=>setLocationId(e.target.value)} sx={{mb:2}}>{locations.map(l=><MenuItem key={l.id} value={String(l.id)}>{l.name}</MenuItem>)}</TextField>
                {services.map((s) => (
                  <Box
                    key={s.name}
                    onClick={() => {
                      setServiceId(String(s.id));
                    }}
                    sx={{
                      p: 2,
                      mb: 1.5,
                      border: "1px solid",
                      borderColor:
                        serviceId === String(s.id) ? "primary.main" : "divider",
                      bgcolor:
                        serviceId === String(s.id)
                          ? "rgba(23,107,98,.06)"
                          : undefined,
                      borderRadius: 2,
                      cursor: "pointer",
                    }}
                  >
                    <Stack direction="row" justifyContent="space-between">
                      <Typography fontWeight={700}>{s.name}</Typography>
                      <Typography color="primary.main" fontWeight={700}>
                        ${(Number(s.price_cents)/100).toFixed(2)}
                      </Typography>
                    </Stack>
                    <Typography variant="body2" color="text.secondary">
                      {s.durations.map(d=>`${d.minutes} min`).join(" / ")}{s.description?` · ${s.description}`:""}
                    </Typography>
                  </Box>
                ))}
              </CardContent>
            </Card>
          </Grid>
          <Grid size={{ xs: 12, md: 7 }}>
            <Card variant="outlined">
              <CardContent>
                <Stack
                  direction={{ xs: "column", sm: "row" }}
                  justifyContent="space-between"
                  mb={2}
                >
                  <Box>
                    <Typography variant="h6">
                      2. Pick a practitioner and time
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      Real availability for the next seven days
                    </Typography>
                  </Box>
                  <TextField select size="small" label="Practitioner" value={practitionerId} onChange={e=>setPractitionerId(e.target.value)} sx={{minWidth:220}}>{practitioners.map(p=><MenuItem key={p.id} value={String(p.id)}>{p.display_name} · {p.credentials||p.discipline}</MenuItem>)}</TextField>
                </Stack>
                <Grid container spacing={1.2}>
                  {slots.slice(0,24).map((t) => (
                    <Grid size={{ xs: 12, sm: 6 }} key={`${t.duration_option_id}-${t.starts_at}`}>
                      <Button
                        fullWidth
                        variant={slot?.starts_at === t.starts_at&&slot.duration_option_id===t.duration_option_id ? "contained" : "outlined"}
                        onClick={() => setSlot(t)}
                      >
                        {new Date(t.starts_at).toLocaleString([],{weekday:"short",month:"short",day:"numeric",hour:"numeric",minute:"2-digit"})}
                      </Button>
                    </Grid>
                  ))}
                </Grid>
                {!practitionerId&&<Alert severity="info" sx={{mt:2}}>No practitioner is assigned to this service yet.</Alert>}{practitionerId&&slots.length===0&&<Alert severity="info" sx={{mt:2}}>No available times were found in the next seven days.</Alert>}{slot && (
                  <Alert severity="info" sx={{ mt: 3 }}>
                    This time is held while you complete your booking. Changes
                    within 24 hours may incur a fee.
                  </Alert>
                )}
                <Button
                  disabled={!slot}
                  onClick={() => setConfirmed(true)}
                  variant="contained"
                  size="large"
                  fullWidth
                  sx={{ mt: 3 }}
                >
                  Continue to client sign-in
                </Button>
              </CardContent>
            </Card>
          </Grid>
        </Grid>}
      </Container>
    </Box>
  );
}

function Portal() {
  return (
    <Box id="portal" py={8} bgcolor="#ecf5f2">
      <Container maxWidth="lg">
        <Box mb={3}>
          <Typography variant="overline" color="primary.main" fontWeight={700}>
            SECURE STAFF WORKSPACE
          </Typography>
          <Typography variant="h3">
            Practice operations, in one place.
          </Typography>
        </Box>
        <StaffSignIn>{(roles) => <StaffPortal roles={roles} />}</StaffSignIn>
      </Container>
    </Box>
  );
}

export default function App() {
  const { config } = useClinicConfig();
  return (
    <>
      <AppBar
        position="sticky"
        color="inherit"
        elevation={0}
        sx={{ borderBottom: "1px solid #e3e9e6" }}
      >
        <Container maxWidth="lg">
          <Toolbar disableGutters>
            <HeartPulse color="#176b62" />
            <Typography fontWeight={800} ml={1.2} flexGrow={1}>
              {config.name}
            </Typography>
            <Stack
              direction="row"
              spacing={1}
              display={{ xs: "none", md: "flex" }}
            >
              <Button href="#booking">Book online</Button>
              <Button href="#portal">Staff portal</Button>
              <UserAccountMenu />
            </Stack>
            <Box sx={{ display: { md: "none" } }}><UserAccountMenu /></Box>
          </Toolbar>
        </Container>
      </AppBar>
      <Box className="hero">
        <Container maxWidth="lg">
          <Grid container spacing={4} alignItems="center" minHeight={470}>
            <Grid size={{ xs: 12, md: 7 }}>
              <Chip
                icon={<HeartPulse size={16} />}
                label="Care that makes room for you"
                sx={{ mb: 2 }}
              />
              <Typography
                variant="h1"
                fontSize={{ xs: "2.7rem", md: "4.3rem" }}
                lineHeight={1.04}
              >
                Feel better, on your schedule.
              </Typography>
              <Typography
                fontSize="1.2rem"
                color="text.secondary"
                maxWidth={590}
                mt={2}
              >
                A calmer way to find the right practitioner, book care, and
                manage your wellness journey.
              </Typography>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                spacing={1.5}
                mt={4}
              >
                <Button href="#booking" size="large" variant="contained">
                  Find an appointment
                </Button>
                <Button href="#portal" size="large" variant="outlined">
                  Staff portal
                </Button>
              </Stack>
            </Grid>
            <Grid size={{ xs: 12, md: 5 }}>
              <Paper elevation={3} sx={{ p: 3, borderRadius: 4 }}>
                <Typography fontWeight={700}>
                  Your next appointment, made simple
                </Typography>
                <Stack spacing={2} mt={2}>
                  {[
                    [CalendarDays, "Browse availability, no sign-in needed"],
                    [Clock3, "15-minute precision, real-time updates"],
                    [ShieldCheck, "Privacy-first care and secure records"],
                  ].map(([Icon, text]) => (
                    <Stack
                      direction="row"
                      spacing={1.4}
                      alignItems="center"
                      key={text as string}
                    >
                      <Box sx={{ p: 1, bgcolor: "#e6f2ef", borderRadius: 2 }}>
                        <Icon size={20} color="#176b62" />
                      </Box>
                      <Typography variant="body2">{text as string}</Typography>
                    </Stack>
                  ))}
                </Stack>
              </Paper>
            </Grid>
          </Grid>
        </Container>
      </Box>
      <Box py={3} borderBottom="1px solid #e3e9e6">
        <Container maxWidth="lg">
          <Stack
            direction={{ xs: "column", md: "row" }}
            justifyContent="space-between"
            spacing={2}
          >
            <Typography>
              <MapPin size={17} /> 240 Queen Street West, Toronto
            </Typography>
            <Typography>
              <UsersRound size={17} /> Massage therapy · Nutrition ·
              Naturopathic care
            </Typography>
          </Stack>
        </Container>
      </Box>
      <Booking />
      <Portal />
      <Box component="footer" py={5} bgcolor="#123b36" color="white">
        <Container maxWidth="lg">
          <Typography fontWeight={800}>
            {config.name}
          </Typography>
          <Typography variant="body2" sx={{ opacity: 0.75, mt: 1 }}>
            Your information belongs to you. Request a copy or correction
            anytime from your client portal.
          </Typography>
        </Container>
      </Box>
    </>
  );
}

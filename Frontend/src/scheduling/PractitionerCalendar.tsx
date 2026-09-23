import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import FullCalendar from '@fullcalendar/react';
import themePlugin from '@fullcalendar/react/themes/monarch';
import dayGridPlugin from '@fullcalendar/react/daygrid';
import timeGridPlugin from '@fullcalendar/react/timegrid';
import frCaLocale from '@fullcalendar/react/locales/fr-ca';
import type { CalendarRef, DatesSetInfo, EventClickInfo, EventInput } from '@fullcalendar/react';
import '@fullcalendar/react/skeleton.css';
import '@fullcalendar/react/themes/monarch/theme.css';
import '@fullcalendar/react/themes/monarch/palettes/green.css';
import { Alert, Box, Button, ButtonGroup, Chip, CircularProgress, Divider, Drawer, FormControlLabel, IconButton, Paper, Stack, Switch, Typography, useMediaQuery, useTheme } from '@mui/material';
import { ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { formatDateTime } from '../i18n/format';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { pagePath } from '../portal/access';

type Appointment = { id: number; starts_at: string; ends_at: string; status: string; delivery_mode: 'clinic' | 'mobile'; service_name: string; client_name: string; location_name: string; timezone: string };
type View = 'timeGridDay' | 'timeGridWeek' | 'dayGridMonth';
const plugins = [themePlugin, dayGridPlugin, timeGridPlugin];
const utc = (value: string) => `${value.replace(' ', 'T')}Z`;
const boundary = (value: Date) => value.toISOString().slice(0, 19) + 'Z';

export function PractitionerCalendar() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const theme = useTheme();
  const narrow = useMediaQuery(theme.breakpoints.down('sm'));
  const calendar = useRef<CalendarRef | null>(null);
  const [range, setRange] = useState<{ start: string; end: string } | null>(null);
  const [rows, setRows] = useState<Appointment[]>([]);
  const [title, setTitle] = useState('');
  const [view, setView] = useState<View>('timeGridWeek');
  const [privateMode, setPrivateMode] = useState(false);
  const [selected, setSelected] = useState<Appointment | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);
  const datesChanged = useCallback((info: DatesSetInfo) => {
    const start = boundary(info.start), end = boundary(info.end);
    setRange(previous => previous?.start === start && previous?.end === end ? previous : { start, end });
    setTitle(info.view.title);
    setView(info.view.type as View);
    setSelected(null);
  }, []);
  useEffect(() => {
    if (!range) return;
    const controller = new AbortController();
    setLoading(true); setError(''); setRows([]);
    void (async () => {
      try {
        const token = await getAccessToken();
        if (controller.signal.aborted) return;
        const params = new URLSearchParams(range);
        const response = await fetch(`${apiBaseUrl}/practitioner/calendar?${params}`, { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal });
        const body = await response.json();
        if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
        if (!controller.signal.aborted) setRows(normalizeNumericIds(body.data));
      } catch (cause) {
        if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load the calendar.'));
      } finally { if (!controller.signal.aborted) setLoading(false); }
    })();
    return () => controller.abort();
  }, [range, revision, getAccessToken, t]);
  const events = useMemo<EventInput[]>(() => rows.map(item => {
    const canceled = item.status.startsWith('canceled');
    const pending = item.status === 'requested';
    const title = privateMode ? t('Private appointment') : `${item.client_name} · ${item.service_name}`;
    return { id: String(item.id), title: canceled ? `${t('Canceled')}: ${title}` : pending ? `${t('Requested')}: ${title}` : title,
      start: utc(item.starts_at), end: utc(item.ends_at),
      backgroundColor: canceled ? theme.palette.grey[500] : pending ? theme.palette.warning.main : theme.palette.primary.main,
      borderColor: canceled ? theme.palette.grey[500] : pending ? theme.palette.warning.main : theme.palette.primary.main,
      textColor: canceled || pending ? theme.palette.getContrastText(canceled ? theme.palette.grey[500] : theme.palette.warning.main) : theme.palette.primary.contrastText,
    };
  }), [rows, privateMode, t, theme]);
  const selectEvent = (info: EventClickInfo) => setSelected(rows.find(item => String(item.id) === info.event.id) ?? null);
  const changeView = (next: View) => calendar.current?.getApi().changeView(next);
  const details = selected;
  return <Stack spacing={2}>
    <Alert severity="info">{t('This calendar shows your clinic appointments. Times use your device timezone ({{zone}}). Personal calendar connections are not enabled yet.', { zone: Intl.DateTimeFormat().resolvedOptions().timeZone })}</Alert>
    <Paper variant="outlined" sx={{ p: { xs: 1.5, sm: 2.5 } }}>
      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', md: 'row' }} gap={1.5} justifyContent="space-between" alignItems={{ md: 'center' }}>
          <Stack direction="row" gap={0.5} alignItems="center"><IconButton aria-label={t('Previous period')} onClick={() => calendar.current?.getApi().prev()}><ChevronLeft /></IconButton><Button onClick={() => calendar.current?.getApi().today()}>{t('Today')}</Button><IconButton aria-label={t('Next period')} onClick={() => calendar.current?.getApi().next()}><ChevronRight /></IconButton><Typography variant="h6" sx={{ ml: 1 }}>{title}</Typography></Stack>
          <Stack direction="row" gap={1} flexWrap="wrap" alignItems="center"><ButtonGroup size="small" aria-label={t('Calendar view')}><Button variant={view === 'timeGridDay' ? 'contained' : 'outlined'} onClick={() => changeView('timeGridDay')}>{t('Day')}</Button><Button variant={view === 'timeGridWeek' ? 'contained' : 'outlined'} onClick={() => changeView('timeGridWeek')}>{t('Week')}</Button><Button variant={view === 'dayGridMonth' ? 'contained' : 'outlined'} onClick={() => changeView('dayGridMonth')}>{t('Month')}</Button></ButtonGroup><Button startIcon={<RefreshCw size={16}/>} disabled={loading} onClick={() => setRevision(value => value + 1)}>{t('Refresh')}</Button></Stack>
        </Stack>
        <FormControlLabel control={<Switch checked={privateMode} onChange={event => { setPrivateMode(event.target.checked); setSelected(null); }} />} label={t('Privacy mode — hide client names')} />
        {error && <Alert severity="error">{error}</Alert>}
        <Box sx={{ position: 'relative', minWidth: 0, '& .fc': { fontFamily: 'inherit', fontSize: { xs: 12, sm: 14 } }, '& .fc-event': { cursor: 'pointer' }, '& .fc-daygrid-day-number, & .fc-col-header-cell-cushion': { color: 'text.primary' } }}>
          {loading && <CircularProgress size={22} aria-label={t('Loading calendar')} sx={{ position: 'absolute', top: 8, right: 8, zIndex: 1 }} />}
          <FullCalendar ref={calendar} plugins={plugins} initialView={narrow ? 'timeGridDay' : 'timeGridWeek'} headerToolbar={false} datesSet={datesChanged} events={events} eventClick={selectEvent} locale={i18n.resolvedLanguage?.startsWith('fr') ? frCaLocale : 'en'} timeZone="local" nowIndicator allDaySlot={false} height="auto" dayMaxEvents={2} expandRows={false} eventTimeFormat={{ hour: 'numeric', minute: '2-digit' }} />
        </Box>
        {!loading && !error && rows.length === 0 && <Typography color="text.secondary">{t('No appointments in this period.')}</Typography>}
      </Stack>
    </Paper>
    <Drawer anchor="right" open={details !== null} onClose={() => setSelected(null)}><Box sx={{ width: { xs: '100vw', sm: 420 }, p: 3 }}><Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="h5">{t('Appointment #{{id}}', { id: details?.id })}</Typography><Button onClick={() => setSelected(null)}>{t('Close')}</Button></Stack><Divider />
      {details && <><Chip sx={{ alignSelf: 'flex-start' }} label={t(details.status.replaceAll('_', ' '))} /><Typography fontWeight={700}>{privateMode ? t('Private appointment') : `${details.client_name} · ${details.service_name}`}</Typography>
        <Typography>{formatDateTime(utc(details.starts_at), i18n.resolvedLanguage, { dateStyle: 'full', timeStyle: 'short' })} – {formatDateTime(utc(details.ends_at), i18n.resolvedLanguage, { timeStyle: 'short' })}</Typography>
        <Typography>{details.location_name} · {t(details.delivery_mode === 'mobile' ? 'On-Site (client location)' : 'Clinic visit')}</Typography>
        {!privateMode && <Button component={Link} to={pagePath('practitioner', 'appointments')}>{t('Open appointments')}</Button>}
      </>}
    </Stack></Box></Drawer>
  </Stack>;
}

import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  Accordion, AccordionDetails, AccordionSummary, Alert, Box, Button, Chip, Divider, Drawer, Grid, IconButton,
  List, ListItemButton, ListItemText, MenuItem, Paper, Stack, TextField, Typography,
} from '@mui/material';
import { Archive, CalendarOff, ChevronDown, Eye, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
type ItemKind = 'rule' | 'override' | 'time_off';
type PanelMode = 'details' | 'new' | 'edit' | null;
type Practitioner = { practitioner_id: number; display_name: string; preferred_name?: string | null; discipline?: string; location_id?: number | null; active?: number | boolean };
type Location = { id: number; name: string; timezone: string };
type Rule = { id: number; practitioner_id: number; location_id: number; practitioner_name: string; location_name: string; weekday: number; start_time: string; end_time: string; valid_from: string; valid_until: string | null; recurrence_interval_weeks: number; active?: number | boolean };
type Exception = { id: number; kind: 'override' | 'time_off'; practitioner_id: number; location_id: number | null; practitioner_name: string; location_name: string | null; starts_at: string; ends_at: string; type: string; reason: string | null };
type SelectedItem = { kind: ItemKind; id: number; rule?: Rule; exception?: Exception };
type Form = { location_id: string; weekday: string; start_time: string; end_time: string; valid_from: string; valid_until: string; starts_at: string; ends_at: string; type: string; reason: string };

const today = () => new Date().toISOString().slice(0, 10);
const initialTime = (hoursAhead: number) => {
  const date = new Date(); date.setMinutes(0, 0, 0); date.setHours(date.getHours() + hoursAhead);
  return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
};
const blankForm = (kind: ItemKind, locationId: number | null): Form => ({
  location_id: locationId ? String(locationId) : '', weekday: '1', start_time: '09:00', end_time: '17:00', valid_from: today(), valid_until: '',
  starts_at: initialTime(1), ends_at: initialTime(2), type: kind === 'time_off' ? 'vacation' : 'blocked', reason: '',
});
const utcToLocalInput = (value: string, timezone: string) => {
  const date = new Date(`${value.replace(' ', 'T')}Z`);
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
  const get = (type: Intl.DateTimeFormatPartTypes) => parts.find(part => part.type === type)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
};

export function AvailabilityAdmin() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [people, setPeople] = useState<Practitioner[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [rules, setRules] = useState<Rule[]>([]);
  const [exceptions, setExceptions] = useState<Exception[]>([]);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [selected, setSelected] = useState<SelectedItem | null>(null);
  const [panelMode, setPanelMode] = useState<PanelMode>(null);
  const [panelKind, setPanelKind] = useState<ItemKind>('rule');
  const [form, setForm] = useState<Form>(() => blankForm('rule', null));
  const [busy, setBusy] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [panelError, setPanelError] = useState('');
  const [saved, setSaved] = useState('');
  const formGuard = useUnsavedForm();

  const load = useCallback(async () => {
    setBusy(true); setLoadError('');
    try {
      const token = await getAccessToken(); const headers = { Authorization: `Bearer ${token}` };
      const responses = await Promise.all([
        fetch(`${api}/admin/practitioners`, { headers }), fetch(`${api}/admin/locations`, { headers }),
        fetch(`${api}/admin/availability-rules`, { headers }), fetch(`${api}/admin/schedule-exceptions`, { headers }),
      ]);
      const bodies = await Promise.all(responses.map(response => response.json()));
      const failed = responses.findIndex(response => !response.ok);
      if (failed >= 0) throw new Error(apiErrorMessage(bodies[failed], responses[failed].status, t('Unable to load availability.')));
      setPeople(normalizeNumericIds<Practitioner[]>(bodies[0].data));
      setLocations(normalizeNumericIds<Location[]>(bodies[1].data));
      setRules(normalizeNumericIds<Rule[]>(bodies[2].data));
      setExceptions(normalizeNumericIds<Exception[]>(bodies[3].data));
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to load availability.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);

  const activePeople = useMemo(() => people.filter(person => person.active === undefined || Boolean(Number(person.active))), [people]);
  const selectedPractitioner = activePeople.find(person => person.practitioner_id === expandedId) ?? null;
  const practitionerRules = (id: number) => rules.filter(rule => rule.practitioner_id === id && rule.active !== 0 && rule.active !== false);
  const practitionerExceptions = (id: number, kind: Exception['kind']) => exceptions.filter(item => item.practitioner_id === id && item.kind === kind);
  const defaultLocationId = (person: Practitioner | null) => Number(person?.location_id ?? locations[0]?.id ?? 0) || null;
  const itemLocation = (item: SelectedItem) => locations.find(location => location.id === (item.rule?.location_id ?? item.exception?.location_id)) ?? null;

  const selectPractitioner = (id: number, expanded: boolean) => { setExpandedId(expanded ? id : null); setSelected(null); setSaved(''); };
  const selectItem = (item: SelectedItem) => { setSelected(current => current?.kind === item.kind && current.id === item.id ? null : item); };
  const startNew = (kind: ItemKind) => {
    if (!selectedPractitioner) return;
    formGuard.markClean(); setPanelKind(kind); setForm(blankForm(kind, defaultLocationId(selectedPractitioner))); setPanelError(''); setPanelMode('new');
  };
  const showDetails = () => { if (!selected) return; formGuard.markClean(); setPanelKind(selected.kind); setPanelError(''); setPanelMode('details'); };
  const startEdit = () => {
    if (!selected) return;
    const location = itemLocation(selected) ?? locations.find(item => item.id === defaultLocationId(selectedPractitioner)) ?? locations[0];
    const next = blankForm(selected.kind, location?.id ?? null);
    if (selected.rule) Object.assign(next, { location_id: String(selected.rule.location_id), weekday: String(selected.rule.weekday), start_time: selected.rule.start_time.slice(0, 5), end_time: selected.rule.end_time.slice(0, 5), valid_from: selected.rule.valid_from, valid_until: selected.rule.valid_until ?? '' });
    if (selected.exception) Object.assign(next, { location_id: String(location?.id ?? ''), starts_at: utcToLocalInput(selected.exception.starts_at, location?.timezone ?? 'America/Toronto'), ends_at: utcToLocalInput(selected.exception.ends_at, location?.timezone ?? 'America/Toronto'), type: selected.exception.type, reason: selected.exception.reason ?? '' });
    formGuard.markClean(); setPanelKind(selected.kind); setForm(next); setPanelError(''); setPanelMode('edit');
  };
  const closePanel = () => {
    if (formGuard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return;
    formGuard.markClean(); setPanelMode(null); setPanelError('');
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (!selectedPractitioner) return;
    setBusy(true); setPanelError(''); setSaved('');
    try {
      const token = await getAccessToken();
      const resource = panelKind === 'rule' ? 'availability-rules' : panelKind === 'override' ? 'availability-overrides' : 'time-off';
      const editingId = panelMode === 'edit' ? selected?.id : null;
      const payload = panelKind === 'rule'
        ? { practitioner_id: selectedPractitioner.practitioner_id, location_id: Number(form.location_id), weekday: Number(form.weekday), start_time: form.start_time, end_time: form.end_time, valid_from: form.valid_from, valid_until: form.valid_until || null, recurrence_interval_weeks: 1 }
        : { practitioner_id: selectedPractitioner.practitioner_id, location_id: Number(form.location_id), starts_at: form.starts_at, ends_at: form.ends_at, ...(panelKind === 'override' ? { override_type: form.type, reason: form.reason } : { reason_type: form.type, notes: form.reason }) };
      const response = await fetch(`${api}/admin/${resource}${editingId ? `/${editingId}` : ''}`, { method: editingId ? 'PATCH' : 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save availability.')));
      formGuard.markClean(); setPanelMode(null); setSelected(null); await load();
      setSaved(t(editingId ? 'Schedule item updated.' : panelKind === 'rule' ? 'Working hours added.' : panelKind === 'override' ? 'Schedule change added.' : 'Time off added.'));
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to save availability.')); }
    finally { setBusy(false); }
  };

  const remove = async () => {
    if (!selected || !window.confirm(t(selected.kind === 'rule' ? 'Archive these working hours?' : 'Remove this schedule item?'))) return;
    setBusy(true); setLoadError('');
    try {
      const token = await getAccessToken(); const resource = selected.kind === 'rule' ? 'availability-rules' : selected.kind === 'override' ? 'availability-overrides' : 'time-off';
      const response = await fetch(`${api}/admin/${resource}/${selected.id}`, { method: 'DELETE', headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json(); if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to remove schedule item.')));
      setSelected(null); await load(); setSaved(t(selected.kind === 'rule' ? 'Working hours archived.' : 'Schedule item removed.'));
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to remove schedule item.')); }
    finally { setBusy(false); }
  };

  const field = <K extends keyof Form>(key: K, value: Form[K]) => setForm(current => ({ ...current, [key]: value }));
  const selectedLocation = locations.find(location => String(location.id) === form.location_id);
  const practitionerName = (person: Practitioner) => person.preferred_name || person.display_name;
  return <Stack spacing={2}>
    {saved && <Alert severity="success" onClose={() => setSaved('')}>{saved}</Alert>}
    {loadError && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{loadError}</Alert>}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Typography variant="h5">{t('Practitioner availability')}</Typography><Typography color="text.secondary">{t('Select a practitioner to manage regular hours, schedule changes, and time off.')}</Typography></Box>
      {activePeople.map(person => {
        const personRules = practitionerRules(person.practitioner_id); const changes = practitionerExceptions(person.practitioner_id, 'override'); const timeOff = practitionerExceptions(person.practitioner_id, 'time_off');
        return <Accordion key={person.practitioner_id} expanded={expandedId === person.practitioner_id} onChange={(_, value) => selectPractitioner(person.practitioner_id, value)} disableGutters elevation={0} square sx={{ '&:before': { display: 'none' }, borderBottom: '1px solid', borderColor: 'divider' }}>
          <AccordionSummary expandIcon={<ChevronDown size={20}/>} sx={{ px: 2.5, py: .75 }}><Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} gap={1} width="100%" pr={2}><Box><Typography fontWeight={750}>{practitionerName(person)}</Typography><Typography variant="body2" color="text.secondary">{person.discipline || t('Practitioner')}</Typography></Box><Stack direction="row" gap={.75} flexWrap="wrap"><Chip size="small" label={t('{{count}} hour rules', { count: personRules.length })}/><Chip size="small" label={t('{{count}} changes', { count: changes.length })}/><Chip size="small" label={t('{{count}} time off', { count: timeOff.length })}/></Stack></Stack></AccordionSummary>
          <AccordionDetails sx={{ p: { xs: 1.5, md: 2.5 }, bgcolor: 'grey.50' }}>
            <Paper variant="outlined" sx={{ p: 1.25, mb: 2 }}><Stack component="nav" aria-label={t('Availability actions')} direction={{ xs: 'column', lg: 'row' }} gap={1} alignItems={{ lg: 'center' }}>
              <Button variant="contained" startIcon={<Plus size={17}/>} onClick={() => startNew('rule')}>{t('Add hours')}</Button>
              <Button startIcon={<Plus size={17}/>} onClick={() => startNew('override')}>{t('Add change')}</Button>
              <Button startIcon={<CalendarOff size={17}/>} onClick={() => startNew('time_off')}>{t('Add time off')}</Button>
              <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', lg: 'block' }, mx: .5 }}/>
              <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={showDetails}>{t('Details')}</Button>
              <Button startIcon={<Pencil size={17}/>} disabled={!selected} onClick={startEdit}>{t('Edit')}</Button>
              <Button color="error" startIcon={selected?.kind === 'rule' ? <Archive size={17}/> : <Trash2 size={17}/>} disabled={!selected || busy} onClick={() => void remove()}>{t(selected?.kind === 'rule' ? 'Archive' : 'Remove')}</Button>
            </Stack></Paper>
            <ScheduleSection title={t('Regular hours')} empty={t('No regular hours have been added.')} items={personRules.map(rule => ({ key: `rule-${rule.id}`, selected: selected?.kind === 'rule' && selected.id === rule.id, primary: `${t(days[rule.weekday - 1])} · ${rule.start_time.slice(0, 5)}–${rule.end_time.slice(0, 5)}`, secondary: `${rule.location_name} · ${t('Effective {{from}}{{until}}', { from: rule.valid_from, until: rule.valid_until ? t(' through {{date}}', { date: rule.valid_until }) : '' })}`, onClick: () => selectItem({ kind: 'rule', id: rule.id, rule }) }))}/>
            <ScheduleSection title={t('Changes')} empty={t('No one-time schedule changes have been added.')} items={changes.map(item => exceptionRow(item, selected, locations, i18n.resolvedLanguage, t, selectItem))}/>
            <ScheduleSection title={t('Time off')} empty={t('No time off has been added.')} items={timeOff.map(item => exceptionRow(item, selected, locations, i18n.resolvedLanguage, t, selectItem))}/>
          </AccordionDetails>
        </Accordion>;
      })}
      {!busy && activePeople.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography variant="h6">{t('No active practitioners')}</Typography><Typography color="text.secondary">{t('Add or activate a practitioner before defining availability.')}</Typography></Box>}
    </Paper>

    <Drawer anchor="right" open={panelMode !== null} onClose={closePanel} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 640 }, maxWidth: '100%' } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Box><Typography variant="overline" color="primary">{t(panelMode === 'details' ? 'Schedule details' : panelMode === 'edit' ? 'Edit schedule item' : panelKind === 'rule' ? 'Add hours' : panelKind === 'override' ? 'Add change' : 'Add time off')}</Typography><Typography variant="h5">{selectedPractitioner ? practitionerName(selectedPractitioner) : ''}</Typography></Box><IconButton aria-label={t('Close panel')} onClick={closePanel}><X/></IconButton></Stack>
      {panelMode === 'details' && selected && <ItemDetails item={selected} locations={locations} language={i18n.resolvedLanguage} edit={startEdit}/>}
      {(panelMode === 'new' || panelMode === 'edit') && <Box component="form" onSubmit={submit} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}>
        <Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><Typography color="text.secondary" mb={2}>{panelKind === 'rule' ? t('Define recurring working hours for this practitioner.') : t('Enter times in {{timezone}}.', { timezone: selectedLocation?.timezone ?? t("the selected location's timezone") })}</Typography><Grid container spacing={2}>
          <Grid size={12}><TextField required select fullWidth label={t(panelKind === 'rule' ? 'Location' : 'Timezone location')} value={form.location_id} onChange={event => field('location_id', event.target.value)}>{locations.map(location => <MenuItem key={location.id} value={String(location.id)}>{location.name} ({location.timezone})</MenuItem>)}</TextField></Grid>
          {panelKind === 'rule' ? <>
            <Grid size={12}><TextField select fullWidth label={t('Day')} value={form.weekday} onChange={event => field('weekday', event.target.value)}>{days.map((day, index) => <MenuItem key={day} value={String(index + 1)}>{t(day)}</MenuItem>)}</TextField></Grid>
            <Grid size={{ xs: 6 }}><TextField required type="time" fullWidth label={t('Starts')} value={form.start_time} onChange={event => field('start_time', event.target.value)} InputLabelProps={{ shrink: true }}/></Grid><Grid size={{ xs: 6 }}><TextField required type="time" fullWidth label={t('Ends')} value={form.end_time} onChange={event => field('end_time', event.target.value)} InputLabelProps={{ shrink: true }}/></Grid>
            <Grid size={{ xs: 6 }}><TextField required type="date" fullWidth label={t('Valid from')} value={form.valid_from} onChange={event => field('valid_from', event.target.value)} InputLabelProps={{ shrink: true }}/></Grid><Grid size={{ xs: 6 }}><TextField type="date" fullWidth label={t('Valid until (optional)')} value={form.valid_until} onChange={event => field('valid_until', event.target.value)} InputLabelProps={{ shrink: true }}/></Grid>
          </> : <>
            <Grid size={{ xs: 12, md: 6 }}><TextField required type="datetime-local" fullWidth label={t('Starts')} value={form.starts_at} onChange={event => field('starts_at', event.target.value)} InputLabelProps={{ shrink: true }}/></Grid><Grid size={{ xs: 12, md: 6 }}><TextField required type="datetime-local" fullWidth label={t('Ends')} value={form.ends_at} onChange={event => field('ends_at', event.target.value)} InputLabelProps={{ shrink: true }}/></Grid>
            <Grid size={{ xs: 12, md: 4 }}><TextField select fullWidth label={t(panelKind === 'override' ? 'Availability' : 'Reason type')} value={form.type} onChange={event => field('type', event.target.value)}>{(panelKind === 'override' ? [['blocked', 'Blocked'], ['available', 'Available']] : [['vacation', 'Vacation'], ['sick', 'Sick'], ['personal', 'Personal'], ['other', 'Other']]).map(([value, label]) => <MenuItem key={value} value={value}>{t(label)}</MenuItem>)}</TextField></Grid>
            <Grid size={{ xs: 12, md: 8 }}><TextField fullWidth label={t('Notes (optional)')} value={form.reason} inputProps={{ maxLength: 500 }} onChange={event => field('reason', event.target.value)}/></Grid>
          </>}
        </Grid>{panelError && <Alert severity="error" sx={{ mt: 2 }}>{panelError}</Alert>}</Box>
        <Stack direction="row" justifyContent="flex-end" gap={1} sx={{ p: 2, borderTop: '1px solid', borderColor: 'divider' }}><Button onClick={closePanel} disabled={busy}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={busy || !form.location_id} startIcon={<Save size={17}/>}>{t(busy ? 'Saving…' : panelMode === 'edit' ? 'Save changes' : panelKind === 'rule' ? 'Add hours' : panelKind === 'override' ? 'Add change' : 'Add time off')}</Button></Stack>
      </Box>}
    </Drawer>
  </Stack>;
}

type Row = { key: string; selected: boolean; primary: string; secondary: string; onClick: () => void };
function ScheduleSection({ title, empty, items }: { title: string; empty: string; items: Row[] }) {
  return <Paper variant="outlined" sx={{ mb: 2, overflow: 'hidden' }}><Box sx={{ px: 2, py: 1.5, borderBottom: items.length ? '1px solid' : 0, borderColor: 'divider' }}><Typography variant="h6">{title}</Typography></Box>{items.length ? <List disablePadding>{items.map(item => <ListItemButton key={item.key} selected={item.selected} onClick={item.onClick} divider><ListItemText primary={<Typography fontWeight={650}>{item.primary}</Typography>} secondary={item.secondary}/></ListItemButton>)}</List> : <Typography color="text.secondary" sx={{ px: 2, pb: 2 }}>{empty}</Typography>}</Paper>;
}
function exceptionRow(item: Exception, selected: SelectedItem | null, locations: Location[], language: string | undefined, t: ReturnType<typeof useTranslation>['t'], select: (item: SelectedItem) => void): Row {
  const labels: Record<string, string> = { blocked: 'Blocked', available: 'Available', vacation: 'Vacation', sick: 'Sick', personal: 'Personal', other: 'Other' };
  const location = locations.find(value => value.id === item.location_id);
  const interval = `${formatInZone(item.starts_at, location?.timezone, language)} – ${formatInZone(item.ends_at, location?.timezone, language)}`;
  return { key: `${item.kind}-${item.id}`, selected: selected?.kind === item.kind && selected.id === item.id, primary: t(labels[item.type] ?? item.type), secondary: `${interval}${item.location_name ? ` · ${item.location_name}` : ''}${item.reason ? ` · ${item.reason}` : ''}`, onClick: () => select({ kind: item.kind, id: item.id, exception: item }) };
}
function formatInZone(value: string, timezone: string | undefined, language: string | undefined) { return new Intl.DateTimeFormat(language, { timeZone: timezone ?? 'UTC', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(`${value.replace(' ', 'T')}Z`)); }
function ItemDetails({ item, locations, language, edit }: { item: SelectedItem; locations: Location[]; language: string | undefined; edit: () => void }) {
  const { t } = useTranslation(); const location = locations.find(value => value.id === (item.rule?.location_id ?? item.exception?.location_id));
  const typeLabels: Record<string, string> = { blocked: 'Blocked', available: 'Available', vacation: 'Vacation', sick: 'Sick', personal: 'Personal', other: 'Other' };
  const rows = item.rule ? [[t('Type'), t('Regular hours')], [t('Day'), t(days[item.rule.weekday - 1])], [t('Time'), `${item.rule.start_time.slice(0, 5)}–${item.rule.end_time.slice(0, 5)}`], [t('Location'), item.rule.location_name], [t('Valid from'), item.rule.valid_from], [t('Valid until'), item.rule.valid_until || t('No end date')]] : item.exception ? [[t('Type'), t(item.kind === 'override' ? 'Schedule change' : 'Time off')], [t(item.kind === 'override' ? 'Availability' : 'Reason type'), t(typeLabels[item.exception.type] ?? item.exception.type)], [t('Starts'), formatInZone(item.exception.starts_at, location?.timezone, language)], [t('Ends'), formatInZone(item.exception.ends_at, location?.timezone, language)], [t('Location'), item.exception.location_name || t('Not set')], [t('Notes'), item.exception.reason || t('Not set')]] : [];
  return <Stack spacing={3} sx={{ p: 3, overflowY: 'auto' }}><Stack divider={<Divider flexItem/>}>{rows.map(([label, value]) => <Box key={label} sx={{ py: 1.5 }}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}</Stack><Button variant="contained" startIcon={<Pencil size={17}/>} onClick={edit}>{t('Edit')}</Button></Stack>;
}

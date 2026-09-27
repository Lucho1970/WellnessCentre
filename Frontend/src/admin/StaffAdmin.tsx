import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Checkbox, Chip, Divider, Drawer, FormControlLabel, IconButton, InputAdornment, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Eye, Pencil, Plus, Save, Search, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useStaffAuth } from '../auth/AuthProvider';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const roleOptions = ['super_admin', 'clinic_admin', 'reception', 'practitioner', 'accountant'];
const scheduleOthers = 'schedule_for_other_practitioners';
const addClients = 'add_clients';
const approveOnsiteArea = 'approve_onsite_service_area';
type Staff = { id: number; display_name: string; email: string; status: string; roles: string[]; permissions: string[] };
type NewStaff = { display_name: string; email: string; tenant_id: string; object_id: string; role: string };
type PanelMode = 'new' | 'details' | 'edit' | null;
const blank = (): NewStaff => ({ display_name: '', email: '', tenant_id: '', object_id: '', role: 'reception' });

export function StaffAdmin() {
  const { t } = useTranslation();
  const { account, getAccessToken } = useStaffAuth();
  const [staff, setStaff] = useState<Staff[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [panelMode, setPanelMode] = useState<PanelMode>(null);
  const [editing, setEditing] = useState<Staff | null>(null);
  const [newStaff, setNewStaff] = useState<NewStaff>(blank);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [panelError, setPanelError] = useState('');
  const [saved, setSaved] = useState('');
  const formGuard = useUnsavedForm();
  const selected = staff.find(member => member.id === selectedId) ?? null;
  const isSelf = (member: Staff) => member.email.toLowerCase() === account?.username?.toLowerCase();
  const filtered = useMemo(() => {
    const term = query.trim().toLocaleLowerCase();
    return term ? staff.filter(member => `${member.display_name} ${member.email} ${member.roles.join(' ')} ${member.status}`.toLocaleLowerCase().includes(term)) : staff;
  }, [staff, query]);

  const load = useCallback(async (preferredId?: number | null) => {
    setBusy(true); setLoadError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/staff`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to load staff.')));
      const loaded = normalizeNumericIds<Staff[]>(body.data).map(member => ({ ...member, permissions: member.permissions ?? [] }));
      setStaff(loaded);
      setSelectedId(current => {
        const requested = Number(preferredId ?? current ?? 0) || null;
        return loaded.some(member => member.id === requested) ? requested : null;
      });
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to load staff.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);

  const startNew = () => { formGuard.markClean(); setNewStaff(blank()); setEditing(null); setPanelError(''); setPanelMode('new'); };
  const showDetails = () => { if (!selected) return; formGuard.markClean(); setPanelError(''); setPanelMode('details'); };
  const startEdit = (member = selected) => {
    if (!member || isSelf(member)) return;
    formGuard.markClean(); setSelectedId(member.id);
    setEditing({ ...member, roles: [...member.roles], permissions: [...member.permissions] });
    setPanelError(''); setPanelMode('edit');
  };
  const closePanel = () => {
    if (formGuard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return;
    formGuard.markClean(); setPanelMode(null); setEditing(null); setPanelError('');
  };
  const setEdit = <K extends keyof Staff>(key: K, value: Staff[K]) => setEditing(current => current ? { ...current, [key]: value } : null);
  const setNew = <K extends keyof NewStaff>(key: K, value: NewStaff[K]) => setNewStaff(current => ({ ...current, [key]: value }));
  const save = async (event: FormEvent) => {
    event.preventDefault();
    if (panelMode === 'edit' && !editing) return;
    setBusy(true); setPanelError(''); setSaved('');
    try {
      const token = await getAccessToken();
      const creating = panelMode === 'new';
      const response = await fetch(creating ? `${api}/admin/staff` : `${api}/admin/staff/${editing!.id}`, {
        method: creating ? 'POST' : 'PATCH',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(creating ? newStaff : editing),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save staff member.')));
      const savedId = Number(body.data?.id ?? editing?.id ?? 0) || null;
      formGuard.markClean(); setPanelMode(null); setEditing(null);
      await load(savedId); setSaved(t(creating ? 'Staff account added.' : 'Staff access updated.'));
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to save staff member.')); }
    finally { setBusy(false); }
  };

  return <Stack spacing={2}>
    <Paper variant="outlined" sx={{ p: 1.5 }}><Stack component="nav" aria-label={t('Staff actions')} direction={{ xs: 'column', md: 'row' }} gap={1} alignItems={{ md: 'center' }}>
      <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New staff member')}</Button>
      <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' }, mx: .5 }}/>
      <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={showDetails}>{t('Details')}</Button>
      <Button startIcon={<Pencil size={17}/>} disabled={!selected || isSelf(selected)} onClick={() => startEdit()}>{t('Edit')}</Button>
      <TextField size="small" label={t('Filter staff')} value={query} onChange={event => setQuery(event.target.value)} sx={{ ml: { md: 'auto' }, minWidth: { md: 250 } }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={16}/></InputAdornment> } }}/>
    </Stack></Paper>
    {saved && <Alert severity="success" onClose={() => setSaved('')}>{saved}</Alert>}
    {loadError && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{loadError}</Alert>}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Typography variant="h5">{t('Staff access')}</Typography><Typography color="text.secondary">{t('Select a staff member to view or edit local access.')}</Typography></Box>
      <List disablePadding aria-label={t('Staff access')}>
        {filtered.map(member => <ListItemButton key={member.id} selected={selectedId === member.id} onClick={() => setSelectedId(member.id)} divider sx={{ py: 1.75, px: 2.5 }}>
          <ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={750}>{member.display_name}{isSelf(member) ? t(' (you)') : ''}</Typography><Chip size="small" label={t(member.status === 'active' ? 'Active' : member.status === 'inactive' ? 'Inactive' : 'Locked')} color={member.status === 'active' ? 'success' : 'default'}/></Stack>} secondary={`${member.email} · ${member.roles.join(', ') || t('No role')}`} />
        </ListItemButton>)}
        {!busy && filtered.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography variant="h6">{t(query ? 'No matching staff' : 'No staff members yet')}</Typography><Typography color="text.secondary">{t(query ? 'Try another name, email, or role.' : 'Add a staff account after configuring its Microsoft Entra identity.')}</Typography></Box>}
      </List>
    </Paper>
    <Drawer anchor="right" open={panelMode !== null} onClose={closePanel} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 620 }, maxWidth: '100%' } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Box><Typography variant="overline" color="primary">{t(panelMode === 'new' ? 'New staff member' : panelMode === 'details' ? 'Staff details' : 'Edit staff access')}</Typography><Typography variant="h5">{panelMode === 'new' ? t('Add staff access') : selected?.display_name}</Typography></Box><IconButton aria-label={t('Close panel')} onClick={closePanel}><X/></IconButton></Stack>
      {panelMode === 'details' && selected && <Stack spacing={3} sx={{ p: 3, overflowY: 'auto' }}><Stack divider={<Divider flexItem/>}>{[[t('Email'), selected.email], [t('Status'), t(selected.status === 'active' ? 'Active' : selected.status === 'inactive' ? 'Inactive' : 'Locked')], [t('Local roles'), selected.roles.join(', ') || t('No role')], [t('Additional permissions'), selected.permissions.join(', ') || t('None')]].map(([label, value]) => <Box key={label} sx={{ py: 1.5 }}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}</Stack><Button variant="contained" startIcon={<Pencil size={17}/>} disabled={isSelf(selected)} onClick={() => startEdit(selected)}>{t('Edit')}</Button></Stack>}
      {(panelMode === 'new' || panelMode === 'edit') && <Box component="form" onSubmit={event => void save(event)} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}>
        <Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><Stack spacing={2}>
          {panelMode === 'new' && <>
            <Alert severity="info">{t('Configure the Microsoft Entra account and app role first. Enter the tenant ID and user object ID from that account.')}</Alert>
            <TextField required label={t('Display name')} value={newStaff.display_name} onChange={event => setNew('display_name', event.target.value)}/>
            <TextField required type="email" label={t('Email')} value={newStaff.email} onChange={event => setNew('email', event.target.value)}/>
            <TextField required label={t('Microsoft Entra tenant ID')} value={newStaff.tenant_id} onChange={event => setNew('tenant_id', event.target.value)}/>
            <TextField required label={t('Microsoft Entra user object ID')} value={newStaff.object_id} onChange={event => setNew('object_id', event.target.value)}/>
            <TextField select required label={t('Initial local role')} value={newStaff.role} onChange={event => setNew('role', event.target.value)}>{roleOptions.map(role => <MenuItem key={role} value={role}>{role.replaceAll('_', ' ')}</MenuItem>)}</TextField>
          </>}
          {panelMode === 'edit' && editing && <>
            <TextField required label={t('Display name')} value={editing.display_name} onChange={event => setEdit('display_name', event.target.value)}/>
            <TextField required type="email" label={t('Email')} value={editing.email} onChange={event => setEdit('email', event.target.value)}/>
            <TextField select label={t('Status')} value={editing.status} onChange={event => setEdit('status', event.target.value)}><MenuItem value="active">{t('Active')}</MenuItem><MenuItem value="inactive">{t('Inactive')}</MenuItem><MenuItem value="locked">{t('Locked')}</MenuItem></TextField>
            <Typography fontWeight={700}>{t('Local roles')}</Typography>
            {roleOptions.map(role => <FormControlLabel key={role} control={<Checkbox checked={editing.roles.includes(role)} onChange={event => {
              const roles = event.target.checked ? [...editing.roles, role] : editing.roles.filter(item => item !== role);
              setEditing({ ...editing, roles, permissions: roles.includes('practitioner') ? editing.permissions : [] });
            }}/>} label={role.replaceAll('_', ' ')}/>)}
            {editing.roles.includes('practitioner') && <>
              <Typography fontWeight={700}>{t('Additional permissions')}</Typography>
              <FormControlLabel control={<Checkbox checked={editing.permissions.includes(scheduleOthers)} onChange={event => setEdit('permissions', event.target.checked ? [...editing.permissions, scheduleOthers] : editing.permissions.filter(item => item !== scheduleOthers))}/>} label={t('Schedule for other practitioners')}/>
              <Typography variant="body2" color="text.secondary">{t('Allows this practitioner to book, reschedule, and cancel appointments assigned to another practitioner.')}</Typography>
              <FormControlLabel control={<Checkbox checked={editing.permissions.includes(addClients)} onChange={event => setEdit('permissions', event.target.checked ? [...editing.permissions, addClients] : editing.permissions.filter(item => item !== addClients))}/>} label={t('Add clients and send invitations')}/>
              <Typography variant="body2" color="text.secondary">{t('Allows creation of client records and invitations for clients this practitioner created. Identity-link approval remains with clinic administration.')}</Typography>
              <FormControlLabel control={<Checkbox checked={editing.permissions.includes(approveOnsiteArea)} onChange={event => setEdit('permissions', event.target.checked ? [...editing.permissions, approveOnsiteArea] : editing.permissions.filter(item => item !== approveOnsiteArea))}/>} label={t('Approve On-Site service areas')}/>
              <Typography variant="body2" color="text.secondary">{t('Allows this practitioner to approve a client visit address for future bookings with the selected service and base location.')}</Typography>
            </>}
          </>}
          {panelError && <Alert severity="error">{panelError}</Alert>}
        </Stack></Box>
        <Stack direction="row" justifyContent="flex-end" gap={1} sx={{ p: 2, borderTop: '1px solid', borderColor: 'divider' }}><Button disabled={busy} onClick={closePanel}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={busy} startIcon={<Save size={17}/>}>{t(busy ? 'Saving…' : panelMode === 'new' ? 'Add staff member' : 'Save changes')}</Button></Stack>
      </Box>}
    </Drawer>
  </Stack>;
}

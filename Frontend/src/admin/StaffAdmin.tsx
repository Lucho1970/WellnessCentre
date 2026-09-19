import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Pencil, Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useStaffAuth } from '../auth/AuthProvider';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const roleOptions = ['super_admin', 'clinic_admin', 'reception', 'practitioner', 'accountant'];
const scheduleOthers = 'schedule_for_other_practitioners';
type Staff = { id: number; display_name: string; email: string; status: string; roles: string[]; permissions: string[] };

export function StaffAdmin() {
  const { t } = useTranslation();
  const { account, getAccessToken } = useStaffAuth();
  const [staff, setStaff] = useState<Staff[]>([]);
  const [editing, setEditing] = useState<Staff | null>(null);
  const [original, setOriginal] = useState<Staff | null>(null);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState('');
  useUnsavedChanges(Boolean(editing && original && JSON.stringify(editing) !== JSON.stringify(original)));
  const load = useCallback(async () => {
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/staff`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to load staff.')));
      setStaff(normalizeNumericIds<Staff[]>(body.data).map((member: Staff) => ({ ...member, permissions: member.permissions ?? [] })));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load staff.')); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const set = <K extends keyof Staff>(key: K, value: Staff[K]) => setEditing(current => current ? { ...current, [key]: value } : null);
  const save = async () => {
    if (!editing) return;
    setError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/staff/${editing.id}`, { method: 'PATCH', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(editing) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save staff member.')));
      setEditing(null); setOriginal(null); await load(); setSaved(t('Staff access updated.'));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save staff member.')); }
  };
  return <Paper variant="outlined" sx={{ p: 3 }}>
    <Typography variant="h5">{t('Staff access')}</Typography>
    <Typography color="text.secondary" mb={2}>{t('Manage local application roles after the Microsoft Entra account and app roles have been configured.')}</Typography>
    {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}{saved && <Alert severity="success" sx={{ mb: 2 }}>{saved}</Alert>}
    <Stack spacing={1}>{staff.map(member => <Stack key={member.id} direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 2 }}>
      <span><Typography fontWeight={700}>{member.display_name}{member.email === account?.username ? t(' (you)') : ''}</Typography><Typography variant="body2" color="text.secondary">{member.email} · {member.roles.join(', ') || t('No role')} · {member.status}</Typography></span>
      <Button startIcon={<Pencil size={16}/>} disabled={member.email === account?.username} onClick={() => { const value={...member,roles:[...member.roles],permissions:[...member.permissions]};setEditing(value);setOriginal(value); }}>{t('Edit access')}</Button>
    </Stack>)}</Stack>
    <Dialog open={Boolean(editing)} onClose={() => { setEditing(null);setOriginal(null); }} fullWidth>
      <DialogTitle>{t('Edit staff access')}</DialogTitle>
      {editing && <DialogContent><Stack spacing={2} pt={1}>
        <TextField label={t('Display name')} value={editing.display_name} onChange={event => set('display_name', event.target.value)}/>
        <TextField type="email" label={t('Email')} value={editing.email} onChange={event => set('email', event.target.value)}/>
        <TextField select label={t('Status')} value={editing.status} onChange={event => set('status', event.target.value)}><MenuItem value="active">{t('Active')}</MenuItem><MenuItem value="inactive">{t('Inactive')}</MenuItem><MenuItem value="locked">{t('Locked')}</MenuItem></TextField>
        <Typography fontWeight={700}>{t('Local roles')}</Typography>
        {roleOptions.map(role => <FormControlLabel key={role} control={<Checkbox checked={editing.roles.includes(role)} onChange={event => {
          const roles = event.target.checked ? [...editing.roles, role] : editing.roles.filter(item => item !== role);
          setEditing({ ...editing, roles, permissions: roles.includes('practitioner') ? editing.permissions : [] });
        }}/>} label={role.replaceAll('_', ' ')}/>)}
        {editing.roles.includes('practitioner') && <>
          <Typography fontWeight={700}>{t('Additional permissions')}</Typography>
          <FormControlLabel control={<Checkbox checked={editing.permissions.includes(scheduleOthers)} onChange={event => set('permissions', event.target.checked ? [...editing.permissions, scheduleOthers] : editing.permissions.filter(item => item !== scheduleOthers))}/>} label={t('Schedule for other practitioners')}/>
          <Typography variant="body2" color="text.secondary">{t('Allows this practitioner to book, reschedule, and cancel appointments assigned to another practitioner.')}</Typography>
        </>}
      </Stack></DialogContent>}
      <DialogActions><Button onClick={() => { setEditing(null);setOriginal(null); }}>{t('Cancel')}</Button><Button variant="contained" startIcon={<Save size={16}/>} onClick={save}>{t('Save')}</Button></DialogActions>
    </Dialog>
  </Paper>;
}

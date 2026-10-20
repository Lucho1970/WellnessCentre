import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert, Box, Button, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, ApiError } from '../shared/api';

type Reference = { type: string; id: number };
type Entry = { id: number; event_group: string; part_number: number; part_count: number; created_at: string; actor_type: string; actor_id: number | null; action: string; route: string; http_method: string; http_status: number; outcome: string; resources: Reference[]; attempted_resources: Reference[]; requested_resources: Reference[] };
type Result = { items: Entry[]; next_before: number | null; filters: Record<string, string | number> };
const dates = () => { const until = new Date(); until.setUTCHours(0,0,0,0); until.setUTCDate(until.getUTCDate()+1); const from = new Date(until); from.setUTCDate(from.getUTCDate()-7); return {from:from.toISOString().slice(0,10),until:until.toISOString().slice(0,10)}; };
export function PrivacyAuditReview() {
  const { t } = useTranslation(); const { getAccessToken } = useStaffAuth();
  const [form, setForm] = useState(() => ({...dates(),actor_type:'',actor_id:'',resource_type:'',resource_id:'',outcome:''}));
  const [active, setActive] = useState<Record<string,string>>(dates);
  const [data, setData] = useState<Result | null>(null); const [error, setError] = useState(''); const [busy,setBusy] = useState(false);
  const pending = useRef<AbortController | null>(null);
  const load = useCallback(async (filters: Record<string,string>, before?: number) => {
    pending.current?.abort(); const controller = new AbortController(); pending.current = controller;
    setData(null); setError(''); setBusy(true);
    try {
      const token = await getAccessToken(); if(controller.signal.aborted)return;
      const query = new URLSearchParams(Object.entries(filters).filter(([,v]) => v !== '')); if(before)query.set('before',String(before));
      const response = await fetch(`${apiBaseUrl}/admin/privacy-audit?${query}`, {headers:{Authorization:`Bearer ${token}`},cache:'no-store',signal:controller.signal});
      const body = await response.json(); if(!response.ok)throw new ApiError(body,response.status,t('Unable to load privacy audit history.'));
      if(!controller.signal.aborted)setData(body.data);
    } catch(cause) { if(!controller.signal.aborted)setError(cause instanceof Error ? cause.message : t('Unable to load privacy audit history.')); }
    finally { if(!controller.signal.aborted)setBusy(false); }
  },[getAccessToken,t]);
  useEffect(() => { void load(active); return () => pending.current?.abort(); },[active,load]);
  const search = (event: FormEvent) => { event.preventDefault(); setActive({...form}); };
  const referenceText = (refs: Reference[]) => refs.map(ref => `${ref.type} #${ref.id}`).join(', ') || '—';
  return <Stack spacing={2}>
    <Alert severity="info">{t('Privacy oversight only. Every search is audited. Clinical records are not opened by this screen.')}</Alert>
    <Paper component="form" onSubmit={search} variant="outlined" sx={{p:2}}>
      <Stack direction="row" gap={2} flexWrap="wrap">
        {(['from','until'] as const).map(key => <TextField key={key} type="date" required label={t(key === 'from' ? 'From date (UTC)' : 'Before date (UTC)')} value={form[key]} slotProps={{inputLabel:{shrink:true}}} onChange={e => setForm({...form,[key]:e.target.value})}/>)}
        <TextField select label={t('Actor type')} value={form.actor_type} onChange={e => setForm({...form,actor_type:e.target.value,actor_id:''})} sx={{minWidth:160}}><MenuItem value="">{t('All')}</MenuItem>{['staff','client','customer_identity','application_admin','anonymous'].map(type => <MenuItem key={type} value={type}>{type}</MenuItem>)}</TextField>
        <TextField label={t('Actor ID')} value={form.actor_id} disabled={!form.actor_type} inputProps={{inputMode:'numeric',pattern:'[1-9][0-9]*'}} onChange={e => setForm({...form,actor_id:e.target.value})}/>
        <TextField select label={t('Resource type')} value={form.resource_type} onChange={e => setForm({...form,resource_type:e.target.value,resource_id:''})} sx={{minWidth:160}}><MenuItem value="">{t('All')}</MenuItem>{['client','appointment','user','client_form_task','form_submission','session_receipt','notification','travel_enquiry','reminder_schedule','audit_event'].map(type => <MenuItem key={type} value={type}>{type}</MenuItem>)}</TextField>
        <TextField label={t('Resource ID')} required={!!form.resource_type} disabled={!form.resource_type} value={form.resource_id} inputProps={{inputMode:'numeric',pattern:'[1-9][0-9]*'}} onChange={e => setForm({...form,resource_id:e.target.value})}/>
        <TextField select label={t('Outcome')} value={form.outcome} onChange={e => setForm({...form,outcome:e.target.value})} sx={{minWidth:140}}><MenuItem value="">{t('All')}</MenuItem>{['success','denied','failure'].map(value => <MenuItem key={value} value={value}>{value}</MenuItem>)}</TextField>
        <Button type="submit" disabled={busy} variant="contained">{t('Search access history')}</Button>
      </Stack>
      <Typography variant="body2" mt={1}>{t('Dates use UTC; the before date is excluded. Search at most 31 days at a time. IDs refer to the recorded actor or resource, not a name match.')}</Typography>
    </Paper>
    {error && <Alert severity="error">{error}</Alert>}
    {busy && <Typography role="status">{t('Loading…')}</Typography>}
    {data && <>
      {!data.items.length && <Typography>{t('No matching access events.')}</Typography>}
      <Box sx={{overflowX:'auto'}}><Table aria-label={t('Clinic access history')}><TableHead><TableRow>{['Audit event','Time (UTC)','Actor','Action','Outcome','References'].map(label => <TableCell key={label}>{t(label)}</TableCell>)}</TableRow></TableHead>
        <TableBody>{data.items.map(row => <TableRow key={row.id}>
          <TableCell>#{row.id}<Typography variant="caption" display="block">{row.event_group} · {row.part_number}/{row.part_count}</Typography></TableCell><TableCell>{row.created_at}</TableCell>
          <TableCell>{row.actor_type} {row.actor_id === null ? '—' : `#${row.actor_id}`}</TableCell><TableCell>{row.action}<Typography variant="body2">{row.http_method} {row.route}</Typography></TableCell>
          <TableCell>{row.outcome} · {row.http_status}</TableCell><TableCell><details><summary>{t('Recorded references')}</summary>
            <Typography variant="body2">{t('Disclosed')}: {referenceText(row.resources)}</Typography><Typography variant="body2">{t('Attempted')}: {referenceText(row.attempted_resources)}</Typography><Typography variant="body2">{t('Requested')}: {referenceText(row.requested_resources)}</Typography>
          </details></TableCell>
        </TableRow>)}</TableBody></Table></Box>
      <Stack direction="row" gap={1}><Button onClick={() => void load(active)} disabled={busy}>{t('Newest results')}</Button><Button onClick={() => void load(active,data.next_before!)} disabled={busy || !data.next_before}>{t('Older results')}</Button></Stack>
    </>}
  </Stack>;
}

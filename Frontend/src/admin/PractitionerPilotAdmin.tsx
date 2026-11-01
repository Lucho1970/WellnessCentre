import { useCallback, useEffect, useRef, useState, type FormEvent, type MouseEvent } from 'react';
import { Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import i18n from '../i18n';
import { useStaffAuth } from '../auth/AuthProvider';
import { ApiError, apiRequest } from '../shared/api';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { useUnsavedForm } from '../shared/UnsavedChanges';

type Status = 'pending' | 'approved' | 'revoked';
type Entry = { id: number; clinic_id: number; label: string; status: Status; version: number; identity_status: string; updated_at: string };
type Detail = { entry: Entry & { issuer: string; subject: string }; events: { id: number; action: string; reason: string; version: number; created_at: string; operator_name: string }[] };
type Listing = { items: Entry[]; next_before_id: number | null };
type Draft = { kind: 'record'; reference: string; label: string; reason: string }
  | { kind: 'review'; detail: Detail; decision: 'approve' | 'revoke' | 'history'; identityVerified: boolean; reason: string };
type Operation = { path: string; body: Record<string, string | number | boolean> };
const statuses: Record<Status, string> = { pending: 'Awaiting pilot approval', approved: 'Allowed for pilot', revoked: 'Pilot access revoked' };

function PilotAdministration({ onDirtyChange }: { onDirtyChange?: (dirty: boolean) => void }) {
  const { t } = useTranslation();
  const { getAccessToken, account } = useStaffAuth();
  const tokenReader = useRef(getAccessToken);
  useEffect(() => { tokenReader.current = getAccessToken; }, [getAccessToken]);
  const validation = useFormValidation();
  const guard = useUnsavedForm();
  const [clinics, setClinics] = useState<{ id: number; name: string }[]>([]);
  const [clinic, setClinic] = useState('');
  const [filter, setFilter] = useState('');
  const [listing, setListing] = useState<Listing | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [pending, setPending] = useState<Operation | null>(null);
  const [busy, setBusy] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const request = useRef<AbortController | null>(null);
  const opener = useRef<HTMLButtonElement | null>(null);
  const heading = useRef<HTMLHeadingElement | null>(null);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => { onDirtyChange?.(guard.dirty); }, [guard.dirty, onDirtyChange]);
  useEffect(() => () => { onDirtyChange?.(false); request.current?.abort(); }, [onDirtyChange]);

  const fail = useCallback((cause: unknown) => {
    if (cause instanceof Error && cause.name === 'AbortError') return;
    validation.capture(cause);
    setError(cause instanceof Error ? cause.message : i18n.t('Unable to load practitioner pilot access.'));
    if (cause instanceof ApiError && [401, 403, 404, 409].includes(cause.status)) {
      setListing(null); setClinics([]); setDraft(null); setPending(null); setBlocked(true); guard.markClean();
    }
  }, [validation.capture, guard.markClean]);

  const load = useCallback(async (before?: number) => {
    request.current?.abort();
    const controller = new AbortController(); request.current = controller; setBusy(true);
    try {
      const headers = { Authorization: `Bearer ${await tokenReader.current()}` };
      const query = new URLSearchParams(); if (filter) query.set('status', filter); if (before) query.set('before_id', String(before));
      const [list, data] = await Promise.all([
        apiRequest<{ items: { id: number; name: string }[] }>('/application/clinics', { headers, signal: controller.signal }),
        clinic ? apiRequest<Listing>(`/application/clinics/${clinic}/pilot-practitioners?${query}`, { headers, signal: controller.signal }) : Promise.resolve(null),
      ]);
      if (controller.signal.aborted) return;
      setClinics(list.items); setBlocked(false); setError('');
      setListing(previous => before && previous && data ? { items: [...previous.items, ...data.items], next_before_id: data.next_before_id } : data);
      if (!clinic && list.items.length) setClinic(String(list.items[0].id));
    } catch (cause) { if (!controller.signal.aborted) fail(cause); }
    finally { if (!controller.signal.aborted) setBusy(false); }
  }, [clinic, filter, fail]);

  useEffect(() => {
    setListing(null); setDraft(null); setPending(null); guard.markClean(); validation.clear();
    void load(); return () => request.current?.abort();
  }, [load, account?.homeAccountId, guard.markClean, validation.clear]);

  const openRecord = (event: MouseEvent<HTMLButtonElement>) => {
    opener.current = event.currentTarget;
    validation.clear(); setSaved(false); setError('');
    setDraft({ kind: 'record', reference: '', label: '', reason: '' }); guard.markClean();
  };
  const review = async (entry: Entry, button: HTMLButtonElement) => {
    opener.current = button;
    setBusy(true); validation.clear(); setSaved(false); setError('');
    request.current?.abort(); const controller = new AbortController(); request.current = controller;
    try {
      const detail = await apiRequest<Detail>(`/application/clinics/${clinic}/pilot-practitioners/${entry.id}`, {
        headers: { Authorization: `Bearer ${await tokenReader.current()}` }, signal: controller.signal,
      });
      if (!controller.signal.aborted) { setDraft({ kind: 'review', detail, decision: detail.entry.status === 'approved' ? 'revoke' : detail.entry.identity_status === 'active' ? 'approve' : detail.entry.status === 'revoked' ? 'history' : 'revoke', identityVerified: false, reason: '' }); guard.markClean(); }
    } catch (cause) { if (!controller.signal.aborted) fail(cause); }
    finally { if (!controller.signal.aborted) setBusy(false); }
  };
  const close = () => {
    if (busy || pending || (guard.dirty && !window.confirm(t('Discard your unsaved changes?')))) return;
    setDraft(null); guard.markClean(); validation.clear();
  };
  const perform = async (operation: Operation) => {
    setBusy(true); setPending(operation); setError(''); validation.clear();
    try {
      await apiRequest(operation.path, { method: 'POST', headers: { Authorization: `Bearer ${await tokenReader.current()}`, 'Content-Type': 'application/json' }, body: JSON.stringify(operation.body) });
    } catch (cause) {
      if (!mounted.current) return;
      if (cause instanceof ApiError && cause.status < 500) setPending(null);
      fail(cause); setBusy(false); return;
    }
    if (!mounted.current) return;
    setPending(null); setDraft(null); setSaved(true); guard.markClean(); await load();
  };
  const submit = (event: FormEvent) => {
    event.preventDefault(); if (!draft || (draft.kind === 'review' && draft.decision === 'history')) return;
    const body: Operation['body'] = { request_key: crypto.randomUUID(), reason: draft.reason };
    let path = `/application/clinics/${clinic}/pilot-practitioners`;
    if (draft.kind === 'record') { body.reference = draft.reference; body.label = draft.label; }
    else {
      path += `/${draft.detail.entry.id}/${draft.decision}`; body.version = draft.detail.entry.version;
      if (draft.decision === 'approve') body.identity_verified = draft.identityVerified;
    }
    void perform({ path, body });
  };
  const edit = (update: Draft) => { setDraft(update); guard.markDirty(); };
  const disabled = busy || !!pending;

  return <Stack spacing={2}>
    <Typography variant="h5" component="h2" ref={heading} tabIndex={-1}>{t('Practitioner pilot access')}</Typography>
    <Typography>{t('Allow verified external accounts into this clinic’s private pilot. Practitioner application approval, activation and clinical permissions remain separate.')}</Typography>
    <Alert severity="info">{t('For ordinary practitioner departure, set Staff access to Inactive and review bookings, publication, billing and communications. Revoking pilot access is an additional sign-in restriction.')}</Alert>
    {error && !draft && <Alert severity="error">{error}</Alert>}
    {saved && <Alert severity="success">{t('Pilot access changes saved.')}</Alert>}
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
      <TextField select label={t('Clinic')} value={clinic} disabled={disabled || !!draft} onChange={event => { setListing(null); setClinic(event.target.value); }}>
        {clinics.map(item => <MenuItem value={String(item.id)} key={item.id}>{item.name}</MenuItem>)}
      </TextField>
      <TextField select label={t('Pilot access filter')} value={filter} disabled={disabled || !!draft} onChange={event => { setListing(null); setFilter(event.target.value); }}>
        <MenuItem value="">{t('All pilot entries')}</MenuItem>
        {Object.entries(statuses).map(([value, label]) => <MenuItem key={value} value={value}>{t(label)}</MenuItem>)}
      </TextField>
      <Button disabled={disabled || !!draft} onClick={() => void load()}>{t('Refresh pilot access')}</Button>
    </Stack>
    {busy && <Typography role="status">{t('Loading…')}</Typography>}
    {listing && !blocked && <>
      <Button disabled={disabled || !!draft} onClick={openRecord}>{t('Record practitioner pilot reference')}</Button>
      {listing.items.length === 0 && <Typography>{t('No practitioner pilot entries for this filter.')}</Typography>}
      {listing.items.map(entry => <Paper key={entry.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
        <Typography variant="h6" component="h3">{entry.label}</Typography><Typography>{t(statuses[entry.status])}</Typography>
        {entry.identity_status !== 'active' && <Alert severity="warning">{t('This identity is inactive. Pilot approval cannot restore it.')}</Alert>}
        <Button disabled={disabled || !!draft} onClick={event => void review(entry, event.currentTarget)}>{t('Review pilot access for {{name}}', { name: entry.label })}</Button>
      </Stack></Paper>)}
      {listing.next_before_id !== null && <Button disabled={disabled || !!draft} onClick={() => void load(listing.next_before_id ?? undefined)}>{t('Load earlier pilot entries')}</Button>}
    </>}
    <Dialog open={draft !== null} onClose={disabled ? undefined : close} fullWidth maxWidth="sm" aria-labelledby="pilot-access-title" disableRestoreFocus slotProps={{ transition: { onExited: () => {
      if (!mounted.current) return;
      const target = opener.current;
      if (target?.isConnected && !target.disabled) target.focus(); else heading.current?.focus();
    } } }}>
      <DialogTitle id="pilot-access-title">{t(draft?.kind === 'record' ? 'Record practitioner pilot reference' : 'Review practitioner pilot access')}</DialogTitle>
      <DialogContent><Stack component="form" id="pilot-access-form" onSubmit={submit} spacing={2} sx={{ pt: 1 }}>
        {error && <Alert severity="error">{error}</Alert>}
        {pending && !busy && <Alert severity="warning">{t('The pilot save outcome is uncertain. Retry the identical change before editing.')}</Alert>}
        {draft?.kind === 'record' && <>
          <TextField name="reference" label={t('Practitioner pilot reference')} helperText={t('Use the WPI1 reference displayed after the intended practitioner signs in. Independently confirm the account owner; the reference is not proof of ownership.')} multiline minRows={4} required value={draft.reference} disabled={disabled} onChange={event => edit({ ...draft, reference: event.target.value })} slotProps={{ htmlInput: { maxLength: 2048, spellCheck: false } }} />
          <TextField name="label" label={t('Operator label')} helperText={t('A label for administration only. Do not enter patient information.')} required value={draft.label} disabled={disabled} onChange={event => edit({ ...draft, label: event.target.value })} slotProps={{ htmlInput: { maxLength: 160 } }} />
          <Typography>{t('Recording a reference creates a pending entry. Review and approval are separate steps.')}</Typography>
        </>}
        {draft?.kind === 'review' && <>
          <Typography variant="h6">{draft.detail.entry.label} · {t(statuses[draft.detail.entry.status])}</Typography>
          <Typography sx={{ overflowWrap: 'anywhere' }}>{t('Sign-in issuer')}: {draft.detail.entry.issuer}</Typography>
          <Typography sx={{ overflowWrap: 'anywhere' }}>{t('Account subject')}: {draft.detail.entry.subject}</Typography>
          {draft.decision !== 'history' && <TextField select label={t('Pilot access decision')} value={draft.decision} disabled={disabled} onChange={event => {
            if (event.target.value === 'approve' || event.target.value === 'revoke') edit({ ...draft, decision: event.target.value, identityVerified: false });
          }}>
            {draft.detail.entry.status !== 'approved' && draft.detail.entry.identity_status === 'active' && <MenuItem value="approve">{t('Approve pilot access')}</MenuItem>}
            {draft.detail.entry.status !== 'revoked' && <MenuItem value="revoke">{t('Revoke pilot access')}</MenuItem>}
          </TextField>}
          {draft.decision === 'approve' && <FormControlLabel control={<Checkbox name="identity_verified" checked={draft.identityVerified} disabled={disabled} onChange={event => edit({ ...draft, identityVerified: event.target.checked })} />} label={t('I independently confirmed this account with its owner for this clinic.')} />}
          <Typography variant="h6" component="h3">{t('Pilot decision history')}</Typography>
          {draft.detail.events.map(item => <Typography key={item.id} variant="body2" sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{item.created_at} UTC · {item.operator_name} · {t(item.action === 'record' ? 'Reference recorded' : item.action === 'approve' ? 'Approve pilot access' : 'Revoke pilot access')} · {item.reason}</Typography>)}
        </>}
        {draft && !(draft.kind === 'review' && draft.decision === 'history') && <TextField name="reason" label={t('Reason for change')} required multiline minRows={2} disabled={disabled} value={draft.reason} onChange={event => edit({ ...draft, reason: event.target.value })} slotProps={{ htmlInput: { maxLength: 500 } }} />}
      </Stack></DialogContent>
      <DialogActions><Button onClick={() => void i18n.changeLanguage(i18n.language === 'fr' ? 'en' : 'fr')}>{i18n.language === 'fr' ? 'EN' : 'FR'}</Button><Button disabled={disabled} onClick={close}>{t('Close')}</Button>
        {pending && !busy ? <Button onClick={() => void perform(pending)}>{t('Retry pilot access change')}</Button>
          : !(draft?.kind === 'review' && draft.decision === 'history') && <Button form="pilot-access-form" type="submit" variant="contained" disabled={disabled || blocked || (draft?.kind === 'review' && draft.decision === 'approve' && !draft.identityVerified)}>{t(draft?.kind === 'record' ? 'Save pending pilot entry' : 'Save pilot access decision')}</Button>}
      </DialogActions>
    </Dialog>
  </Stack>;
}

export const PractitionerPilotAdmin = withFormValidation(PilotAdministration);

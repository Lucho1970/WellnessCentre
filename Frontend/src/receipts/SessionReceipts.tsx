import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Checkbox, Divider, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { customerFetch } from '../customer/session';
import { formatCad } from '../i18n/format';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

type Request = <T = unknown>(path: string, init?: RequestInit) => Promise<T>;
type Receipt = { id: number; number: string; appointment_id: number; status: 'issued' | 'void'; service_name: string; practitioner_name: string; total_cents: number; void_reason: string | null };
type Template = { issuer_name: string; issuer_address: string; issuer_contact: string; clinic_logo: string };
type Profile = { practitioner_name: string; designation: string; registration_number: string; practitioner_logo: string };
type Context = { enabled: boolean; can_edit_template: boolean; template: Template | null; template_version: number; profile: Profile | null; profile_version: number; items: Receipt[]; appointment: { id: number; version: number; status: string; client_name: string; practitioner_name: string; service_name: string; base_cents: number | null; travel_cents: number } };
const emptyTemplate: Template = { issuer_name: '', issuer_address: '', issuer_contact: '', clinic_logo: '' };
const emptyProfile: Profile = { practitioner_name: '', designation: '', registration_number: '', practitioner_logo: '' };
const moneyCents = (value: string): number | null => /^\d{1,7}(\.\d{1,2})?$/.test(value) ? Number(value.split('.')[0]) * 100 + Number((value.split('.')[1] ?? '').padEnd(2, '0')) : null;
const localToday = () => new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

async function download(request: Request, id: number) {
  const data = await request<{ pdf_base64: string; filename: string }>(`/receipts/${id}/pdf`);
  const bytes = Uint8Array.from(atob(data.pdf_base64), char => char.charCodeAt(0));
  const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
  const anchor = document.createElement('a'); anchor.href = url; anchor.download = data.filename; document.body.append(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 30000);
}

function ReceiptRows({ items, request, staff = false, changed, onBusyChange, onDirtyChange }: { items: Receipt[]; request: Request; staff?: boolean; changed?: () => void; onBusyChange?: (value: boolean) => void; onDirtyChange?: (value: boolean) => void }) {
  const { t, i18n } = useTranslation();
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const [voiding, setVoiding] = useState<Receipt | null>(null), [reason, setReason] = useState(''), [kind, setKind] = useState('recording_error'), [confirmed, setConfirmed] = useState(false);
  useUnsavedChanges(Boolean(voiding));
  useEffect(() => { onBusyChange?.(busy); }, [busy, onBusyChange]);
  useEffect(() => { onDirtyChange?.(Boolean(voiding)); }, [voiding, onDirtyChange]);
  const run = async (action: () => Promise<void>) => { setBusy(true); setError(''); try { await action(); } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load receipts.')); } finally { setBusy(false); } };
  return <Stack spacing={2}>{error && <Alert severity="error">{error}</Alert>}{items.map(row => <Paper variant="outlined" key={row.id} sx={{ p: 2 }}>
    <Typography fontWeight={700}>{row.number} · {formatCad(row.total_cents, i18n.resolvedLanguage)}</Typography>
    <Typography>{row.service_name} · {row.practitioner_name} · {t('Appointment #{{id}}', { id: row.appointment_id })}</Typography>
    {row.status === 'void' ? <Alert severity="warning">{t('Voided receipt')}{staff && row.void_reason ? ` · ${row.void_reason}` : ''}</Alert> : <Stack direction="row" gap={1} flexWrap="wrap">
      <Button disabled={busy} onClick={() => void run(() => download(request, row.id))}>{t('Download receipt PDF')}</Button>
      {staff && <Button color="warning" disabled={busy} onClick={() => { setVoiding(row); setReason(''); setConfirmed(false); }}>{t('Void receipt')}</Button>}
    </Stack>}
  </Paper>)}
    {voiding && <Stack component="form" spacing={2} onSubmit={event => { event.preventDefault(); void run(async () => { await request(`/receipts/${voiding.id}/void`, { method: 'POST', body: JSON.stringify({ kind, reason, confirmed }) }); setVoiding(null); changed?.(); }); }}>
      <Alert severity="warning">{t('Voiding preserves the original receipt and payment history. This does not send money back to the client.')}</Alert>
      <TextField select label={t('Reason category')} value={kind} onChange={e => setKind(e.target.value)}><MenuItem value="recording_error">{t('Payment recording error')}</MenuItem><MenuItem value="refunded">{t('Full refund already completed')}</MenuItem></TextField>
      <TextField required label={t('Correction reason')} value={reason} inputProps={{ minLength: 5, maxLength: 500 }} onChange={e => setReason(e.target.value)} />
      <FormControlLabel control={<Checkbox checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />} label={t('I confirm this is a recording correction or that the full refund has already been made.')} />
      <Stack direction="row"><Button disabled={busy} onClick={() => setVoiding(null)}>{t('Cancel')}</Button><Button type="submit" disabled={busy || !confirmed || reason.trim().length < 5}>{t('Confirm void')}</Button></Stack>
    </Stack>}
  </Stack>;
}

function LogoField({ value, onChange, disabled }: { value: string; onChange: (value: string) => void; disabled: boolean }) {
  const { t } = useTranslation(); const [error, setError] = useState('');
  return <Stack spacing={1}><Button component="label" disabled={disabled}>{t('Upload receipt logo')}<input hidden type="file" accept="image/png,image/jpeg" onChange={event => { const file = event.target.files?.[0]; if (!file) return; if (file.size > 150000) { setError(t('Use a PNG or JPEG logo smaller than 150 KB.')); return; } const reader = new FileReader(); reader.onload = () => { onChange(String(reader.result)); setError(''); }; reader.readAsDataURL(file); event.target.value = ''; }} /></Button>{value && <Box><Box component="img" src={value} alt={t('Receipt logo preview')} sx={{ maxWidth: 150, maxHeight: 65 }} /><Button disabled={disabled} onClick={() => onChange('')}>{t('Remove logo')}</Button></Box>}{error && <Alert severity="error">{error}</Alert>}</Stack>;
}

export function SessionReceipt({ appointmentId, request, onStateChange, onStatusChange }: { appointmentId: number; request: Request; onStateChange?: (state: { busy: boolean; dirty: boolean }) => void; onStatusChange?: (status: string, version: number) => void }) {
  const { t, i18n } = useTranslation();
  const [data, setData] = useState<Context | null>(null), [error, setError] = useState(''), [busy, setBusy] = useState(false), [reload, setReload] = useState(0), [notice, setNotice] = useState('');
  const [template, setTemplate] = useState(emptyTemplate), [profile, setProfile] = useState(emptyProfile);
  const [editTemplate, setEditTemplate] = useState(false), [editProfile, setEditProfile] = useState(false), [dirty, setDirty] = useState(false);
  const [tax, setTax] = useState('0.00'), [amount, setAmount] = useState(''), [method, setMethod] = useState('cash'), [paidOn, setPaidOn] = useState(localToday), [confirmed, setConfirmed] = useState(false);
  const pending = useRef<{ key: string; body: Record<string, unknown> } | null>(null);
  const [childBusy, setChildBusy] = useState(false), [childDirty, setChildDirty] = useState(false);
  useEffect(() => { onStateChange?.({ busy: busy || childBusy, dirty: dirty || childDirty }); }, [busy, childBusy, dirty, childDirty, onStateChange]);
  useEffect(() => () => onStateChange?.({ busy: false, dirty: false }), [onStateChange]);
  useUnsavedChanges(dirty);
  useEffect(() => { let active = true; setData(null); void request<Context>(`/appointments/${appointmentId}/receipt`).then(value => { if (!active) return; setData(value); if (value.enabled) { setTemplate(value.template ?? emptyTemplate); setProfile(value.profile ?? { ...emptyProfile, practitioner_name: value.appointment.practitioner_name }); } }).catch(cause => { if (active) setError(cause.message); }); return () => { active = false; }; }, [appointmentId, request, reload]);
  const refresh = () => { setDirty(false); setConfirmed(false); setReload(x => x + 1); };
  const run = async (action: () => Promise<void>) => { setBusy(true); setError(''); setNotice(''); try { await action(); } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save receipt.')); } finally { setBusy(false); } };
  if (!data) return error ? <Alert severity="error">{error}</Alert> : null;
  if (!data.enabled) return null;
  const taxCents = moneyCents(tax), amountCents = moneyCents(amount);
  const total = data.appointment.base_cents === null || taxCents === null ? null : data.appointment.base_cents + data.appointment.travel_cents + taxCents;
  const activeReceipt = data.items.some(row => row.status === 'issued');
  const issue = (event: FormEvent) => { event.preventDefault(); void run(async () => {
    const body = { version: data.appointment.version, template_version: data.template_version, profile_version: data.profile_version, tax_cents: taxCents, amount_cents: amountCents, method, paid_on: paidOn, confirmed, language: i18n.resolvedLanguage?.startsWith('fr') ? 'fr' : 'en' };
    const key = JSON.stringify(body); if (!pending.current || pending.current.key !== key) pending.current = { key, body: { ...body, idempotency_key: crypto.randomUUID() } };
    await request(`/appointments/${appointmentId}/receipt`, { method: 'POST', body: JSON.stringify(pending.current.body) }); pending.current = null; setNotice(t('Receipt issued and available in the client portal.')); onStatusChange?.('paid', data.appointment.version + 1); refresh();
  }); };
  return <Stack spacing={2}><Divider /><Typography variant="h6">{t('Session receipt')}</Typography><Button disabled={busy || childBusy} onClick={() => { if (!dirty || window.confirm(t('Discard your unsaved changes?'))) { setEditTemplate(false); setEditProfile(false); refresh(); } }}>{t('Refresh')}</Button>{error && <Alert severity="error">{error}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    {data.can_edit_template && <Button disabled={busy} onClick={() => setEditTemplate(v => !v)}>{t('Clinic receipt template')}</Button>}
    {editTemplate && data.can_edit_template && <Stack component="form" spacing={2} onSubmit={event => { event.preventDefault(); void run(async () => { await request('/receipts/template', { method: 'PUT', body: JSON.stringify({ ...template, version: data.template_version }) }); setEditTemplate(false); refresh(); }); }}>
      <Typography>{t('The clinic maintains this template for future receipts. Issued receipts stay unchanged.')}</Typography>
      {(['issuer_name', 'issuer_address', 'issuer_contact'] as const).map((key, i) => <TextField key={key} required label={t(['Receipt issuer name', 'Receipt business address', 'Receipt contact details'][i])} value={template[key]} inputProps={{ maxLength: [150, 300, 190][i] }} disabled={busy} onChange={event => { setDirty(true); setTemplate({ ...template, [key]: event.target.value }); }} />)}
      <LogoField disabled={busy} value={template.clinic_logo} onChange={clinic_logo => { setDirty(true); setTemplate({ ...template, clinic_logo }); }} /><Button type="submit" disabled={busy}>{t('Save clinic receipt template')}</Button>
    </Stack>}
    <Button disabled={busy} onClick={() => setEditProfile(v => !v)}>{t('Practitioner receipt details')}</Button>
    {editProfile && <Stack component="form" spacing={2} onSubmit={event => { event.preventDefault(); void run(async () => { await request(`/appointments/${appointmentId}/receipt-profile`, { method: 'PUT', body: JSON.stringify({ ...profile, version: data.profile_version }) }); setEditProfile(false); refresh(); }); }}>
      <Typography>{t('These details apply to this practitioner, clinic and service. Use only the credentials applicable to this service.')}</Typography>
      {(['practitioner_name', 'designation', 'registration_number'] as const).map((key, i) => <TextField key={key} required={key !== 'registration_number'} label={t(['Professional name on receipt', 'Professional designation', 'Registration number if applicable'][i])} value={profile[key]} inputProps={{ maxLength: i === 0 ? 150 : 100 }} disabled={busy} onChange={event => { setDirty(true); setProfile({ ...profile, [key]: event.target.value }); }} />)}
      <LogoField disabled={busy} value={profile.practitioner_logo} onChange={practitioner_logo => { setDirty(true); setProfile({ ...profile, practitioner_logo }); }} /><Button type="submit" disabled={busy}>{t('Save practitioner receipt details')}</Button>
    </Stack>}
    <ReceiptRows items={data.items} request={request} staff onBusyChange={setChildBusy} onDirtyChange={setChildDirty} changed={() => { onStatusChange?.('completed', data.appointment.version + 1); refresh(); }} />
    {!activeReceipt && !editTemplate && !editProfile && data.appointment.status === 'completed' && <Stack component="form" spacing={2} onSubmit={issue} onChange={() => setDirty(true)}>
      {!data.template || !data.profile ? <Alert severity="info">{t('Save the clinic template and practitioner service details before issuing a receipt.')}</Alert> : <>
        <Typography>{data.appointment.client_name} · {data.appointment.service_name}</Typography>
        <Typography>{data.template.issuer_name} · {data.profile.practitioner_name} · {data.profile.designation} · {data.profile.registration_number}</Typography>
        <Typography>{data.template.issuer_address} · {data.template.issuer_contact}</Typography>
        <Typography>{t('Treatment {{treatment}} + On-Site fee {{mobile}} (before applicable taxes)', { treatment: formatCad(data.appointment.base_cents ?? 0, i18n.resolvedLanguage), mobile: formatCad(data.appointment.travel_cents, i18n.resolvedLanguage) })}</Typography>
        <TextField required label={t('Tax amount CAD')} value={tax} inputProps={{ inputMode: 'decimal', pattern: '\\d+(\\.\\d{1,2})?' }} disabled={busy} onChange={e => { setTax(e.target.value); setConfirmed(false); }} />
        <Typography fontWeight={700}>{t('Receipt total')}: {total === null ? t('Unavailable') : formatCad(total, i18n.resolvedLanguage)}</Typography>
        <TextField required label={t('Amount received CAD')} value={amount} disabled={busy} inputProps={{ inputMode: 'decimal', pattern: '\\d+(\\.\\d{1,2})?' }} onChange={e => setAmount(e.target.value)} />
        <TextField select label={t('Payment method')} value={method} disabled={busy} onChange={e => setMethod(e.target.value)}>{[['cash', 'Cash'], ['debit', 'Debit'], ['credit', 'Credit card'], ['etransfer', 'E-transfer'], ['other', 'Other']].map(([value, label]) => <MenuItem key={value} value={value}>{t(label)}</MenuItem>)}</TextField>
        <TextField required type="date" label={t('Payment date')} value={paidOn} disabled={busy} InputLabelProps={{ shrink: true }} inputProps={{ max: localToday() }} onChange={e => setPaidOn(e.target.value)} />
        <FormControlLabel control={<Checkbox disabled={busy} checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />} label={t('I confirm the issuer, service credentials, tax and full payment received are correct.')} />
        <Button type="submit" variant="contained" disabled={busy || !confirmed || total === null || amountCents !== total || total < 1}>{t('Record payment and issue receipt')}</Button>
      </>}
    </Stack>}
    {!activeReceipt && data.appointment.status !== 'completed' && <Typography>{t('Mark the session completed before recording payment and issuing a receipt.')}</Typography>}
  </Stack>;
}

export function CustomerReceipts() {
  const { t } = useTranslation(); const [items, setItems] = useState<Receipt[]>([]), [page, setPage] = useState(1), [more, setMore] = useState(false), [loading, setLoading] = useState(false), [error, setError] = useState(''), [enabled, setEnabled] = useState(true);
  useEffect(() => { const controller = new AbortController(); setLoading(true); setError(''); setItems([]); void customerFetch(`/receipts?page=${page}`, { signal: controller.signal }).then(data => { if (!controller.signal.aborted) { setItems(data.items); setMore(data.has_more); setEnabled(data.enabled); } }).catch(cause => { if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); }); return () => controller.abort(); }, [page]);
  return <Stack spacing={2}><Typography variant="h6">{t('My receipts')}</Typography><Typography>{t('Download a receipt to save it or print it from your PDF viewer.')}</Typography>{error && <Alert severity="error">{error}</Alert>}{!loading && !error && !items.length && <Typography>{t(enabled ? 'No receipts have been issued yet.' : 'Receipts are not enabled for this clinic.')}</Typography>}{loading ? <Typography>{t('Loading receipts…')}</Typography> : <ReceiptRows items={items} request={customerFetch} />}<Stack direction="row"><Button disabled={loading || page === 1} onClick={() => setPage(v => v - 1)}>{t('Previous')}</Button><Button disabled={loading || !more} onClick={() => setPage(v => v + 1)}>{t('Next')}</Button></Stack></Stack>;
}

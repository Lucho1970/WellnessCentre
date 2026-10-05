import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, Divider, MenuItem, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type QualificationType = { id: number; name: string; requires_expiry: boolean | number; active: boolean | number };
type Qualification = { id: number; qualification_name: string; qualification_type_id: number; issuer: string; issued_on: string; expires_on: string | null; status: 'pending' | 'verified' | 'rejected' | 'revoked'; days_until_expiry: number | null; renewal_warning: boolean; review_note: string | null };
type Props = { practitionerId?: number; admin?: boolean };

export function PractitionerQualifications({ practitionerId, admin = false }: Props) {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [types, setTypes] = useState<QualificationType[]>([]);
  const [records, setRecords] = useState<Qualification[]>([]);
  const [typeId, setTypeId] = useState('');
  const [issuer, setIssuer] = useState('');
  const [issuedOn, setIssuedOn] = useState('');
  const [expiresOn, setExpiresOn] = useState('');
  const [newType, setNewType] = useState('');
  const [typeRequiresExpiry, setTypeRequiresExpiry] = useState(true);
  const [reviewNote, setReviewNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const base = practitionerId ? `${api}/admin/practitioners/${practitionerId}/qualifications` : `${api}/profile/qualifications`;
  const selectedType = types.find(item => item.id === Number(typeId));

  const load = useCallback(async () => {
    const token = await getAccessToken();
    const headers = { Authorization: `Bearer ${token}` };
    const [typeResponse, recordResponse] = await Promise.all([fetch(`${api}/qualifications/types`, { headers }), fetch(base, { headers })]);
    const [typeBody, recordBody] = await Promise.all([typeResponse.json(), recordResponse.json()]);
    if (!typeResponse.ok) throw new Error(apiErrorMessage(typeBody, typeResponse.status, t('Unable to load qualifications.')));
    if (!recordResponse.ok) throw new Error(apiErrorMessage(recordBody, recordResponse.status, t('Unable to load qualifications.')));
    setTypes(normalizeNumericIds<QualificationType[]>(typeBody.data));
    setRecords(normalizeNumericIds<Qualification[]>(recordBody.data));
  }, [base, getAccessToken, t]);
  useEffect(() => { setError(''); void load().catch(cause => setError(cause instanceof Error ? cause.message : t('Unable to load qualifications.'))); }, [load]);

  const request = async (url: string, method: string, body: object) => {
    const token = await getAccessToken();
    const response = await fetch(url, { method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const payload = await response.json();
    if (!response.ok) throw new Error(apiErrorMessage(payload, response.status, t('Unable to save qualification.')));
    await load();
  };
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      await request(base, 'POST', { qualification_type_id: Number(typeId), issuer, issued_on: issuedOn, expires_on: expiresOn || null });
      setTypeId(''); setIssuer(''); setIssuedOn(''); setExpiresOn(''); setMessage(t('Qualification submitted for review.'));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save qualification.')); }
    finally { setBusy(false); }
  };
  const createType = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try { await request(`${api}/admin/qualifications/types`, 'POST', { name: newType, requires_expiry: typeRequiresExpiry }); setNewType(''); setMessage(t('Qualification type added.')); }
    catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save qualification.')); }
    finally { setBusy(false); }
  };
  const review = async (record: Qualification, status: 'verified' | 'rejected' | 'revoked') => {
    if (status === 'revoked' && !window.confirm(t('Revoke this qualification?'))) return;
    setBusy(true); setError(''); setMessage('');
    try { await request(`${api}/admin/qualifications/${record.id}/review`, 'PATCH', { status, review_note: reviewNote }); setReviewNote(''); setMessage(t('Qualification review saved.')); }
    catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save qualification.')); }
    finally { setBusy(false); }
  };

  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Stack spacing={2}>
    <Box><Typography variant="h6">{t('Practitioner qualifications')}</Typography><Typography color="text.secondary">{t('Submitted records need administrator review. Expiry warnings appear here 90 days before expiry; email reminders are not enabled yet.')}</Typography></Box>
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => void load().catch(cause => setError(cause instanceof Error ? cause.message : t('Unable to load qualifications.')))}>{t('Retry')}</Button>}>{error}</Alert>}
    {message && <Alert severity="success">{message}</Alert>}
    {records.some(record => record.renewal_warning) && <Alert severity="warning">{t('At least one verified qualification needs renewal soon or has expired.')}</Alert>}
    {records.length === 0 && <Typography color="text.secondary">{t('No qualifications recorded yet.')}</Typography>}
    {records.map(record => <Box key={record.id} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, p: 2 }}><Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={700}>{record.qualification_name}</Typography><Chip size="small" color={record.status === 'verified' && (record.days_until_expiry === null || record.days_until_expiry >= 0) ? 'success' : record.status === 'pending' ? 'warning' : 'default'} label={t(record.status === 'pending' ? 'Pending review' : record.status === 'verified' ? 'Verified by clinic' : record.status === 'rejected' ? 'Rejected' : 'Revoked')}/>{record.renewal_warning && <Chip size="small" color="warning" label={record.days_until_expiry !== null && record.days_until_expiry < 0 ? t('Expired') : t('Renewal due soon')}/>}</Stack><Typography variant="body2" color="text.secondary">{record.issuer} · {t('Issued')}: {record.issued_on} · {t('Expires')}: {record.expires_on || t('No expiry')}</Typography>{record.review_note && <Typography variant="body2">{t('Review note')}: {record.review_note}</Typography>}{admin && (record.status === 'pending' || record.status === 'verified') && <Stack spacing={1} mt={1.5}><TextField size="small" label={t('Review note')} value={reviewNote} onChange={event => setReviewNote(event.target.value)} inputProps={{ maxLength: 500 }}/><Stack direction="row" gap={1} flexWrap="wrap">{record.status === 'pending' ? <><Button size="small" disabled={busy} onClick={() => void review(record, 'verified')}>{t('Verify')}</Button><Button size="small" color="error" disabled={busy || !reviewNote.trim()} onClick={() => void review(record, 'rejected')}>{t('Reject')}</Button></> : <Button size="small" color="error" disabled={busy || !reviewNote.trim()} onClick={() => void review(record, 'revoked')}>{t('Revoke')}</Button>}</Stack></Stack>}</Box>)}
    <Divider/>
    <Box component="form" onSubmit={submit}><Typography fontWeight={700} mb={1}>{t('Submit qualification or renewal')}</Typography><Stack spacing={1.5}><TextField select required size="small" label={t('Qualification type')} value={typeId} onChange={event => setTypeId(event.target.value)}>{types.filter(item => Boolean(Number(item.active))).map(item => <MenuItem key={item.id} value={item.id}>{item.name}</MenuItem>)}</TextField><TextField required size="small" label={t('Issuing organization')} value={issuer} onChange={event => setIssuer(event.target.value)} inputProps={{ maxLength: 150 }}/><Stack direction={{ xs: 'column', sm: 'row' }} gap={1}><TextField required fullWidth size="small" type="date" label={t('Issue date')} value={issuedOn} onChange={event => setIssuedOn(event.target.value)} slotProps={{ inputLabel: { shrink: true } }}/><TextField required={Boolean(Number(selectedType?.requires_expiry))} fullWidth size="small" type="date" label={t('Expiry date')} value={expiresOn} onChange={event => setExpiresOn(event.target.value)} slotProps={{ inputLabel: { shrink: true } }}/></Stack><Button type="submit" variant="contained" disabled={busy || !selectedType}>{t('Submit for review')}</Button></Stack></Box>
    {admin && <><Divider/><Box component="form" onSubmit={createType}><Typography fontWeight={700} mb={1}>{t('Add qualification type')}</Typography><Stack spacing={1.5}><TextField required size="small" label={t('Qualification name')} value={newType} onChange={event => setNewType(event.target.value)} inputProps={{ maxLength: 150 }}/><Stack direction="row" alignItems="center" gap={1}><Switch checked={typeRequiresExpiry} onChange={event => setTypeRequiresExpiry(event.target.checked)}/><Typography>{t('Expiry date required')}</Typography></Stack><Button type="submit" disabled={busy}>{t('Add type')}</Button></Stack></Box></>}
  </Stack></Paper>;
}

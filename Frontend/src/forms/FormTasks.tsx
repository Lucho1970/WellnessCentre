import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, Chip, CircularProgress, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import type { FormRequest } from './api';
import { TypedFormField, typedAnswer, type FormAnswer } from './TypedFormField';
import { questionGroups, type FormSection } from './sections';
import { ChoiceField } from './ChoiceField';
import { customerFetch } from '../customer/session';

export type Question = { id: string; label: string; label_fr: string; type: 'text' | 'yes_no' | 'consent' | 'date' | 'phone' | 'email' | 'single_choice' | 'multiple_choice'; options?: { id: string; label: string; label_fr: string }[]; required: boolean; no_future?: boolean; section_id?: string };
export type Definition = { instructions: string; instructions_fr: string; questions: Question[]; sections?: FormSection[] };
type Task = { id: number; name: string; status: string; version: number; template_version: number; practitioner_name: string; can_read_answers: boolean; required: boolean | number; appointment_id: number | null };
type Detail = Task & { definition: Definition; answers: Record<string, FormAnswer> | null };
export function FormTasks({ request, clientId, customer = false, onLockedChange }: { request: FormRequest; clientId?: number; customer?: boolean; onLockedChange?: (locked: boolean) => void }) {
  const { t, i18n } = useTranslation();
  const [items, setItems] = useState<Task[]>([]), [detail, setDetail] = useState<Detail | null>(null), [answers, setAnswers] = useState<Record<string, FormAnswer>>({});
  const [page, setPage] = useState(1), [more, setMore] = useState(false), [reload, setReload] = useState(0), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(false), [confirmed, setConfirmed] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [templates, setTemplates] = useState<{ id: number; name: string; version: number; practitioner_name: string }[] | null>(null), [templateId, setTemplateId] = useState(''), [templatePage, setTemplatePage] = useState(1), [templateMore, setTemplateMore] = useState(false), [assignmentKey, setAssignmentKey] = useState(() => crypto.randomUUID());
  const dirty = Boolean(customer && detail?.status === 'pending' && (Object.keys(answers).length || confirmed));
  useUnsavedChanges(dirty || uncertain);
  useEffect(() => { onLockedChange?.(busy || uncertain || dirty); }, [busy, uncertain, dirty, onLockedChange]);
  useEffect(() => () => onLockedChange?.(false), [onLockedChange]);
  const listPath = customer ? '/forms' : `/clients/${clientId}/forms`;
  const taskPath = (id: number) => customer ? `/forms/${id}` : `/forms/tasks/${id}`;
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void request(`${listPath}?page=${page}`, { signal: controller.signal }).then(data => { if (!controller.signal.aborted) { if (!Array.isArray(data.items)) throw new Error(t('Invalid form response.')); setItems(data.items); setMore(data.has_more); } }).catch(cause => { if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request, listPath, page, reload, t]);
  const open = async (id: number) => { setBusy(true); setError(''); setNotice(''); setDetail(null); try { const data = await request(taskPath(id)); if (Number(data.id) !== id || !Array.isArray(data.definition?.questions)) throw new Error(t('Invalid form response.')); setDetail(data); setAnswers(data.answers ?? {}); setConfirmed(false); } catch (cause) { setError((cause as Error).message); } finally { setBusy(false); } };
  const leave = () => { if (dirty && !window.confirm(t('Discard these unsaved answers?'))) return; setDetail(null); setAnswers({}); setConfirmed(false); setError(''); };
  const submit = async () => {
    if (!detail) return;
    try { detail.definition.questions.forEach(q => typedAnswer(q, answers[q.id])); } catch (cause) { setError(t((cause as Error).message)); return; }
    setBusy(true); setError('');
    try {
      const data = await request(`${taskPath(detail.id)}/submit`, { method: 'POST', body: JSON.stringify({ version: Number(detail.version), answers: Object.fromEntries(detail.definition.questions.map(q => [q.id, typedAnswer(q, answers[q.id])]).filter(([, value]) => value !== undefined)), confirmed: true }) });
      if (Number(data.id) !== detail.id || !['submitted', 'reviewed'].includes(data.status)) throw new Error(t('Invalid form response.'));
      setUncertain(false); setDetail(null); setAnswers({}); setConfirmed(false); setNotice(t('Form submitted. Your answers have been saved.')); setReload(v => v + 1);
    } catch (cause) { const status = Number((cause as { status?: number }).status); setUncertain(!status || status >= 500); setError((cause as Error).message); }
    finally { setBusy(false); }
  };
  const action = async (task: Task, value: string) => { setBusy(true); setError(''); try { await request(taskPath(task.id), { method: 'PATCH', body: JSON.stringify({ action: value, version: Number(task.version) }) }); setDetail(null); setReload(v => v + 1); } catch (cause) { setError((cause as Error).message); } finally { setBusy(false); } };
  const loadTemplates = async (next: number) => { setBusy(true); setError(''); try { const data = await request(`/forms/templates?page=${next}`); setTemplates(data.items); setTemplateMore(data.has_more); setTemplatePage(next); setTemplateId(''); } catch (cause) { setError((cause as Error).message); } finally { setBusy(false); } };
  const assign = async () => { setBusy(true); setError(''); try { const data = await request(listPath, { method: 'POST', body: JSON.stringify({ template_id: Number(templateId), idempotency_key: assignmentKey }) }); if (!Number.isSafeInteger(data.id) || data.id < 1) throw new Error(t('Invalid form response.')); setUncertain(false); setTemplates(null); setAssignmentKey(crypto.randomUUID()); setNotice(t('Form assigned.')); setReload(v => v + 1); } catch (cause) { const status = Number((cause as { status?: number }).status); setUncertain(!status || status >= 500); setError((cause as Error).message); } finally { setBusy(false); } };
  return <Stack spacing={2}>
    <Typography variant="h6">{t(customer ? 'My forms' : 'Client forms')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    {uncertain && <Alert severity="warning">{t('Confirmation is uncertain. Retry the same answers before making changes.')}</Alert>}
    {detail ? <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Button disabled={busy || uncertain} onClick={leave}>{t('Back to forms')}</Button><Typography variant="h6">{detail.name}</Typography><Typography>{t('Form version {{version}}', { version: detail.template_version })} · {detail.practitioner_name}</Typography>
      <Typography sx={{ whiteSpace: 'pre-wrap' }}>{i18n.language.startsWith('fr') && detail.definition.instructions_fr ? detail.definition.instructions_fr : detail.definition.instructions}</Typography>
      <Stack component="form" spacing={2} onSubmit={event => { event.preventDefault(); void submit(); }}>
        {questionGroups(detail.definition).map(group => <Stack key={group.section?.id ?? 'unsectioned'} component={group.section ? 'fieldset' : 'div'} spacing={2} sx={{ minWidth: 0, m: 0, p: group.section ? 2 : 0, border: group.section ? '1px solid' : 0, borderColor: 'divider', borderRadius: 1 }}>{group.section && <Typography component="legend" variant="h6">{i18n.language.startsWith('fr') && group.section.title_fr ? group.section.title_fr : group.section.title}</Typography>}{group.section && <Typography sx={{ whiteSpace: 'pre-wrap' }}>{i18n.language.startsWith('fr') && group.section.description_fr ? group.section.description_fr : group.section.description}</Typography>}{!group.section && Boolean(detail.definition.sections?.length) && <Typography variant="h6">{t('Questions without a section')}</Typography>}{group.questions.map(q => {
          const label = i18n.language.startsWith('fr') && q.label_fr ? q.label_fr : q.label; const disabled = busy || uncertain || !customer || detail.status !== 'pending';
          return ['single_choice', 'multiple_choice'].includes(q.type) ? <ChoiceField key={q.id} question={q} label={label} value={answers[q.id]} disabled={disabled} onChange={value => setAnswers(a => ({ ...a, [q.id]: value }))}/> : ['date', 'phone', 'email'].includes(q.type) ? <TypedFormField key={q.id} question={q} label={label} value={answers[q.id]} disabled={disabled} onChange={value => setAnswers(a => ({ ...a, [q.id]: value }))}/> : q.type === 'text' ? <TextField key={q.id} multiline fullWidth minRows={2} required={q.required} label={label} disabled={disabled} value={typeof answers[q.id] === 'string' ? answers[q.id] : ''} inputProps={{ maxLength: 2000 }} onChange={event => setAnswers(a => ({ ...a, [q.id]: event.target.value }))}/>
            : q.type === 'yes_no' ? <TextField key={q.id} select fullWidth label={label} required={q.required} disabled={disabled} value={answers[q.id] === true ? 'yes' : answers[q.id] === false ? 'no' : ''} onChange={event => setAnswers(a => { const next = { ...a }; if (!event.target.value) delete next[q.id]; else next[q.id] = event.target.value === 'yes'; return next; })}><MenuItem value="">{t('Not answered')}</MenuItem><MenuItem value="yes">{t('Yes')}</MenuItem><MenuItem value="no">{t('No')}</MenuItem></TextField>
              : <FormControlLabel key={q.id} label={label} control={<Checkbox required={q.required && !disabled} disabled={disabled} checked={answers[q.id] === true} onChange={event => setAnswers(a => ({ ...a, [q.id]: event.target.checked }))}/>}/>;
        })}</Stack>)}
        {customer && detail.status === 'pending' && <><FormControlLabel label={t('I reviewed these answers and confirm submission.')} control={<Checkbox checked={confirmed} disabled={busy || uncertain} onChange={event => setConfirmed(event.target.checked)}/>}/><Button type="submit" variant="contained" disabled={busy || !confirmed}>{t(uncertain ? 'Retry confirmation' : 'Submit form')}</Button></>}
      </Stack>
      {!customer && detail.status === 'submitted' && <Button disabled={busy || uncertain} variant="contained" onClick={() => void action(detail, 'review')}>{t('Mark form reviewed')}</Button>}
      {detail.status !== 'pending' && <Alert severity="info">{t('Submitted answers are preserved with this form version.')}</Alert>}
    </Stack></Paper> : <>
      {loading ? <CircularProgress aria-label={t('Loading forms')}/> : !items.length && !error && <Typography>{t('No forms assigned yet.')}</Typography>}
      {!customer && <Button disabled={busy || uncertain} onClick={() => void loadTemplates(1)}>{t('Assign form')}</Button>}
      {templates && <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}><TextField select label={t('Form template')} disabled={busy || uncertain} value={templateId} onChange={event => { setTemplateId(event.target.value); setAssignmentKey(crypto.randomUUID()); }}>{templates.map(f => <MenuItem key={f.id} value={f.id}>{f.name} · {f.practitioner_name} · {t('Form version {{version}}', { version: f.version })}</MenuItem>)}</TextField><Stack direction="row" gap={1} flexWrap="wrap"><Button disabled={busy || !templateId} onClick={() => void assign()}>{t(uncertain ? 'Retry confirmation' : 'Confirm assignment')}</Button><Button disabled={busy || uncertain || templatePage === 1} onClick={() => void loadTemplates(templatePage - 1)}>{t('Previous')}</Button><Button disabled={busy || uncertain || !templateMore} onClick={() => void loadTemplates(templatePage + 1)}>{t('Next')}</Button><Button disabled={busy || uncertain} onClick={() => setTemplates(null)}>{t('Cancel')}</Button></Stack></Stack></Paper>}
      {items.map(task => <Paper key={task.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}><Typography fontWeight={700}>{task.name}</Typography><Typography>{task.practitioner_name} · {t('Form version {{version}}', { version: task.template_version })}</Typography><Stack direction="row" gap={1} flexWrap="wrap"><Chip label={t(`Form status: ${task.status}`)}/>{Boolean(Number(task.required)) && <Chip label={t('Required')}/>}</Stack>{task.appointment_id && <Typography>{t('Appointment #{{id}}', { id: task.appointment_id })}</Typography>}<Stack direction="row" gap={1}>{task.can_read_answers && task.status !== 'revoked' && <Button disabled={busy || uncertain} onClick={() => void open(task.id)}>{t(customer && task.status === 'pending' ? 'Complete form' : 'View form')}</Button>}{!customer && task.status === 'pending' && <Button disabled={busy || uncertain} onClick={() => { if (window.confirm(t('Revoke this pending form assignment?'))) void action(task, 'revoke'); }}>{t('Revoke assignment')}</Button>}</Stack>{!customer && !task.can_read_answers && <Typography variant="body2">{t('Answers are visible only to the client and assigned practitioner.')}</Typography>}</Stack></Paper>)}
      <Stack direction="row" spacing={1}><Button disabled={loading || page === 1 || busy || uncertain} onClick={() => setPage(p => p - 1)}>{t('Previous')}</Button><Button disabled={loading || !more || busy || uncertain} onClick={() => setPage(p => p + 1)}>{t('Next')}</Button><Button disabled={loading || busy || uncertain} onClick={() => setReload(v => v + 1)}>{t('Refresh')}</Button></Stack>
    </>}
  </Stack>;
}
export function CustomerForms({ onLockedChange }: { onLockedChange: (locked: boolean) => void }) { return <FormTasks request={customerFetch} customer onLockedChange={onLockedChange}/>; }

import { Alert, FormControlLabel, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useState } from 'react';
import { Button, Checkbox, Chip, CircularProgress, MenuItem, Paper, Stack, Typography } from '@mui/material';
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
type Answers = Record<string, FormAnswer>;
type Amendment = { id: number; answers: Answers; reason: string; created_at: string; reviewed_at: string | null };
type Detail = Task & { definition: Definition; answers: Answers | null; original_answers?: Answers | null; draft?: { answers: Answers; updated_at: string } | null; amendments?: Amendment[]; answer_changes_enabled?: boolean };
type AnswerWrite = { kind: 'submit' | 'draft' | 'amendments'; body: { version: number; answers: Answers; confirmed?: boolean; reason?: string; idempotency_key?: string } };
function FormTasksForm({ request, clientId, customer = false, onLockedChange }: { request: FormRequest; clientId?: number; customer?: boolean; onLockedChange?: (locked: boolean) => void }) {
  const formValidation = useFormValidation();
  const { t, i18n } = useTranslation();
  const [items, setItems] = useState<Task[]>([]), [detail, setDetail] = useState<Detail | null>(null), [answers, setAnswers] = useState<Record<string, FormAnswer>>({});
  const [page, setPage] = useState(1), [more, setMore] = useState(false), [reload, setReload] = useState(0), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(false), [confirmed, setConfirmed] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [templates, setTemplates] = useState<{ id: number; name: string; version: number; practitioner_name: string }[] | null>(null), [templateId, setTemplateId] = useState(''), [templatePage, setTemplatePage] = useState(1), [templateMore, setTemplateMore] = useState(false), [assignmentKey, setAssignmentKey] = useState(() => crypto.randomUUID());
  const [savedAnswers, setSavedAnswers] = useState<Answers>({}), [amending, setAmending] = useState(false), [reason, setReason] = useState(''), [pendingWrite, setPendingWrite] = useState<AnswerWrite | null>(null);
  const editable = customer && (detail?.status === 'pending' || amending);
  const dirty = Boolean(editable && (JSON.stringify(answers) !== JSON.stringify(savedAnswers) || confirmed || reason));
  useUnsavedChanges(dirty || uncertain);
  useEffect(() => { onLockedChange?.(busy || uncertain || dirty); }, [busy, uncertain, dirty, onLockedChange]);
  useEffect(() => () => onLockedChange?.(false), [onLockedChange]);
  const listPath = customer ? '/forms' : `/clients/${clientId}/forms`;
  const taskPath = (id: number) => customer ? `/forms/${id}` : `/forms/tasks/${id}`;
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); (formValidation.clear(), setError(''));
    void request(`${listPath}?page=${page}`, { signal: controller.signal }).then(data => { if (!controller.signal.aborted) { if (!Array.isArray(data.items)) throw new Error(t('Invalid form response.')); setItems(data.items); setMore(data.has_more); } }).catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request, listPath, page, reload, t]);
  const open = async (id: number) => { setBusy(true); (formValidation.clear(), setError('')); setNotice(''); setDetail(null); try { const data = await request(taskPath(id)); if (Number(data.id) !== id || !Array.isArray(data.definition?.questions)) throw new Error(t('Invalid form response.')); const loaded = data.status === 'pending' && customer ? data.draft?.answers ?? {} : data.answers ?? {}; setDetail(data); setAnswers(loaded); setSavedAnswers(loaded); setAmending(false); setReason(''); setPendingWrite(null); setConfirmed(false); if (customer && data.status === 'pending' && data.draft) setNotice(t('Your saved answer draft has been loaded.')); } catch (cause) { formValidation.capture(cause); setError((cause as Error).message); } finally { setBusy(false); } };
  const leave = () => { if (dirty && !window.confirm(t('Discard these unsaved answers?'))) return; setDetail(null); setAnswers({}); setSavedAnswers({}); setAmending(false); setReason(''); setPendingWrite(null); setConfirmed(false); (formValidation.clear(), setError('')); };
  const submit = async (kind: AnswerWrite['kind'] = amending ? 'amendments' : 'submit') => {
    if (!detail) return;
    if (kind !== 'draft' && !confirmed) return;
    const fields: Record<string, string> = {};
    for (const question of detail.definition.questions) { try { typedAnswer(question, answers[question.id], kind === 'draft'); } catch (cause) { fields[`answers.${question.id}`] = t((cause as Error).message); } }
    if (Object.keys(fields).length) { formValidation.capture({ fields }); setError(t('Check the form questions and required answers.')); return; }
    if (kind === 'amendments' && !reason.trim()) { formValidation.capture({ fields: { reason: t('Enter a reason for the amendment.') } }); return; }
    const write: AnswerWrite = uncertain && pendingWrite ? pendingWrite : { kind, body: { version: Number(detail.version), answers: Object.fromEntries(detail.definition.questions.map(q => [q.id, typedAnswer(q, answers[q.id], kind === 'draft')]).filter(([, value]) => value !== undefined)) as Answers, ...(kind === 'draft' ? {} : { confirmed: true }), ...(kind === 'amendments' ? { reason: reason.trim() } : {}), ...(kind === 'submit' ? {} : { idempotency_key: crypto.randomUUID() }) } };
    setPendingWrite(write);
    setBusy(true); (formValidation.clear(), setError(''));
    try {
      const data = await request(`${taskPath(detail.id)}/${write.kind}`, { method: 'POST', body: JSON.stringify(write.body) });
      if (Number(data.id) !== detail.id || (write.kind === 'draft' ? data.status !== 'pending' || !Number.isSafeInteger(data.version) || data.version <= write.body.version : !['submitted', 'reviewed'].includes(data.status))) throw new Error(t('Invalid form response.'));
      setUncertain(false); setPendingWrite(null); setConfirmed(false);
      if (write.kind === 'draft') { setDetail({ ...detail, version: data.version }); setAnswers(write.body.answers); setSavedAnswers(write.body.answers); setNotice(t('Answer draft saved. It has not been submitted.')); }
      else { setDetail(null); setAnswers({}); setSavedAnswers({}); setAmending(false); setReason(''); setNotice(t(write.kind === 'amendments' ? 'Amendment saved. The original submission is preserved.' : 'Form submitted. Your answers have been saved.')); setReload(v => v + 1); }
    } catch (cause) { formValidation.capture(cause); const status = Number((cause as { status?: number }).status); setUncertain(!status || status >= 500); if (status && status < 500) setPendingWrite(null); setError((cause as Error).message); }
    finally { setBusy(false); }
  };
  const action = async (task: Task, value: string) => { setBusy(true); (formValidation.clear(), setError('')); try { await request(taskPath(task.id), { method: 'PATCH', body: JSON.stringify({ action: value, version: Number(task.version) }) }); setDetail(null); setReload(v => v + 1); } catch (cause) { formValidation.capture(cause); setError((cause as Error).message); } finally { setBusy(false); } };
  const loadTemplates = async (next: number) => { setBusy(true); (formValidation.clear(), setError('')); try { const data = await request(`/forms/templates?page=${next}`); setTemplates(data.items); setTemplateMore(data.has_more); setTemplatePage(next); setTemplateId(''); } catch (cause) { formValidation.capture(cause); setError((cause as Error).message); } finally { setBusy(false); } };
  const assign = async () => { setBusy(true); (formValidation.clear(), setError('')); try { const data = await request(listPath, { method: 'POST', body: JSON.stringify({ template_id: Number(templateId), idempotency_key: assignmentKey }) }); if (!Number.isSafeInteger(data.id) || data.id < 1) throw new Error(t('Invalid form response.')); setUncertain(false); setTemplates(null); setAssignmentKey(crypto.randomUUID()); setNotice(t('Form assigned.')); setReload(v => v + 1); } catch (cause) { formValidation.capture(cause); const status = Number((cause as { status?: number }).status); setUncertain(!status || status >= 500); setError((cause as Error).message); } finally { setBusy(false); } };
  return <Stack spacing={2}>
    <Typography variant="h6">{t(customer ? 'My forms' : 'Client forms')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    {uncertain && <Alert severity="warning">{t('Confirmation is uncertain. Retry the same answers before making changes.')}</Alert>}
    {detail ? <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Button disabled={busy || uncertain} onClick={leave}>{t('Back to forms')}</Button><Typography variant="h6">{detail.name}</Typography><Typography>{t('Form version {{version}}', { version: detail.template_version })} · {detail.practitioner_name}</Typography>
      {customer && <Button disabled={busy || uncertain} onClick={() => { if (!dirty || window.confirm(t('Discard these unsaved answers?'))) void open(detail.id); }}>{t('Reload form')}</Button>}
      <Typography sx={{ whiteSpace: 'pre-wrap' }}>{i18n.language.startsWith('fr') && detail.definition.instructions_fr ? detail.definition.instructions_fr : detail.definition.instructions}</Typography>
      <Stack component="form" spacing={2} onSubmit={event => { event.preventDefault(); void submit(); }}>
        {questionGroups(detail.definition).map(group => <Stack key={group.section?.id ?? 'unsectioned'} component={group.section ? 'fieldset' : 'div'} spacing={2} sx={{ minWidth: 0, m: 0, p: group.section ? 2 : 0, border: group.section ? '1px solid' : 0, borderColor: 'divider', borderRadius: 1 }}>{group.section && <Typography component="legend" variant="h6">{i18n.language.startsWith('fr') && group.section.title_fr ? group.section.title_fr : group.section.title}</Typography>}{group.section && <Typography sx={{ whiteSpace: 'pre-wrap' }}>{i18n.language.startsWith('fr') && group.section.description_fr ? group.section.description_fr : group.section.description}</Typography>}{!group.section && Boolean(detail.definition.sections?.length) && <Typography variant="h6">{t('Questions without a section')}</Typography>}{group.questions.map(q => {
          const label = i18n.language.startsWith('fr') && q.label_fr ? q.label_fr : q.label; const disabled = busy || uncertain || !editable || (amending && q.type === 'consent');
          return ['single_choice', 'multiple_choice'].includes(q.type) ? <ChoiceField key={q.id} question={q} label={label} value={answers[q.id]} disabled={disabled} onChange={value => setAnswers(a => ({ ...a, [q.id]: value }))}/> : ['date', 'phone', 'email'].includes(q.type) ? <TypedFormField key={q.id} question={q} label={label} value={answers[q.id]} disabled={disabled} onChange={value => setAnswers(a => ({ ...a, [q.id]: value }))}/> : q.type === 'text' ? <TextField name={`answers.${q.id}`} key={q.id} multiline fullWidth minRows={2} required={q.required} label={label} disabled={disabled} value={typeof answers[q.id] === 'string' ? answers[q.id] : ''} inputProps={{ maxLength: 2000 }} onChange={event => setAnswers(a => ({ ...a, [q.id]: event.target.value }))}/>
            : q.type === 'yes_no' ? <TextField name={`answers.${q.id}`} key={q.id} select fullWidth label={label} required={q.required} disabled={disabled} value={answers[q.id] === true ? 'yes' : answers[q.id] === false ? 'no' : ''} onChange={event => setAnswers(a => { const next = { ...a }; if (!event.target.value) delete next[q.id]; else next[q.id] = event.target.value === 'yes'; return next; })}><MenuItem value="">{t('Not answered')}</MenuItem><MenuItem value="yes">{t('Yes')}</MenuItem><MenuItem value="no">{t('No')}</MenuItem></TextField>
              : <FormControlLabel name={`answers.${q.id}`} key={q.id} label={label} control={<Checkbox required={q.required && !disabled} disabled={disabled} checked={answers[q.id] === true} onChange={event => setAnswers(a => ({ ...a, [q.id]: event.target.checked }))}/>}/>;
        })}</Stack>)}
        {editable && <>
          {detail.status === 'pending' && detail.answer_changes_enabled && <Button type="button" disabled={busy || (uncertain && pendingWrite?.kind !== 'draft')} onClick={() => void submit('draft')}>{t(uncertain && pendingWrite?.kind === 'draft' ? 'Retry draft save' : 'Save answer draft')}</Button>}
          {amending && <><Alert severity="info">{t('Consent answers are preserved. Contact your practitioner about consent withdrawal.')}</Alert><TextField name="reason" fullWidth multiline required label={t('Reason for amendment')} value={reason} disabled={busy || uncertain} inputProps={{ maxLength: 1000 }} onChange={event => setReason(event.target.value)}/></>}
          <FormControlLabel name="confirmed" label={t(amending ? 'I reviewed these changes and confirm the amendment.' : 'I reviewed these answers and confirm submission.')} control={<Checkbox checked={confirmed} disabled={busy || uncertain} onChange={event => setConfirmed(event.target.checked)}/>}/>
          <Button type="submit" variant="contained" disabled={busy || !confirmed || (uncertain && pendingWrite?.kind === 'draft')}>{t(uncertain ? 'Retry confirmation' : amending ? 'Submit amendment' : 'Submit form')}</Button>
        </>}
      </Stack>
      {customer && detail.answer_changes_enabled && ['submitted', 'reviewed'].includes(detail.status) && !amending && <Button disabled={busy || uncertain} onClick={() => { setAmending(true); setConfirmed(false); setReason(''); setNotice(''); }}>{t('Amend answers')}</Button>}
      {Boolean(detail.amendments?.length) && <Stack spacing={2}><Typography variant="h6">{t('Answer history')}</Typography><AnswerSnapshot definition={detail.definition} answers={detail.original_answers ?? {}} title={t('Original submission')}/>{detail.amendments?.map((amendment, index) => <Stack key={amendment.id} spacing={1}><AnswerSnapshot definition={detail.definition} answers={amendment.answers} title={t('Amendment {{number}}', { number: index + 1 })}/><Typography sx={{ whiteSpace: 'pre-wrap' }}>{amendment.reason}</Typography><Typography variant="body2">{t('Saved at {{time}}', { time: new Date(amendment.created_at.replace(' ', 'T') + 'Z').toLocaleString(i18n.language) })} · {t(amendment.reviewed_at ? 'Reviewed' : 'Awaiting practitioner review')}</Typography></Stack>)}</Stack>}
      {!customer && detail.status === 'submitted' && <Button disabled={busy || uncertain} variant="contained" onClick={() => void action(detail, 'review')}>{t('Mark form reviewed')}</Button>}
      {detail.status !== 'pending' && <Alert severity="info">{t('Submitted answers are preserved with this form version.')}</Alert>}
    </Stack></Paper> : <>
      {loading ? <CircularProgress aria-label={t('Loading forms')}/> : !items.length && !error && <Typography>{t('No forms assigned yet.')}</Typography>}
      {!customer && <Button disabled={busy || uncertain} onClick={() => void loadTemplates(1)}>{t('Assign form')}</Button>}
      {templates && <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}><TextField name="templateId" select label={t('Form template')} disabled={busy || uncertain} value={templateId} onChange={event => { setTemplateId(event.target.value); setAssignmentKey(crypto.randomUUID()); }}>{templates.map(f => <MenuItem key={f.id} value={f.id}>{f.name} · {f.practitioner_name} · {t('Form version {{version}}', { version: f.version })}</MenuItem>)}</TextField><Stack direction="row" gap={1} flexWrap="wrap"><Button disabled={busy || !templateId} onClick={() => void assign()}>{t(uncertain ? 'Retry confirmation' : 'Confirm assignment')}</Button><Button disabled={busy || uncertain || templatePage === 1} onClick={() => void loadTemplates(templatePage - 1)}>{t('Previous')}</Button><Button disabled={busy || uncertain || !templateMore} onClick={() => void loadTemplates(templatePage + 1)}>{t('Next')}</Button><Button disabled={busy || uncertain} onClick={() => setTemplates(null)}>{t('Cancel')}</Button></Stack></Stack></Paper>}
      {items.map(task => <Paper key={task.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}><Typography fontWeight={700}>{task.name}</Typography><Typography>{task.practitioner_name} · {t('Form version {{version}}', { version: task.template_version })}</Typography><Stack direction="row" gap={1} flexWrap="wrap"><Chip label={t(`Form status: ${task.status}`)}/>{Boolean(Number(task.required)) && <Chip label={t('Required')}/>}</Stack>{task.appointment_id && <Typography>{t('Appointment #{{id}}', { id: task.appointment_id })}</Typography>}<Stack direction="row" gap={1}>{task.can_read_answers && task.status !== 'revoked' && <Button disabled={busy || uncertain} onClick={() => void open(task.id)}>{t(customer && task.status === 'pending' ? 'Complete form' : 'View form')}</Button>}{!customer && task.status === 'pending' && <Button disabled={busy || uncertain} onClick={() => { if (window.confirm(t('Revoke this pending form assignment?'))) void action(task, 'revoke'); }}>{t('Revoke assignment')}</Button>}</Stack>{!customer && !task.can_read_answers && <Typography variant="body2">{t('Answers are visible only to the client and assigned practitioner.')}</Typography>}</Stack></Paper>)}
      <Stack direction="row" spacing={1}><Button disabled={loading || page === 1 || busy || uncertain} onClick={() => setPage(p => p - 1)}>{t('Previous')}</Button><Button disabled={loading || !more || busy || uncertain} onClick={() => setPage(p => p + 1)}>{t('Next')}</Button><Button disabled={loading || busy || uncertain} onClick={() => setReload(v => v + 1)}>{t('Refresh')}</Button></Stack>
    </>}
  </Stack>;
}
export const FormTasks = withFormValidation(FormTasksForm);

function AnswerSnapshot({ definition, answers, title }: { definition: Definition; answers: Answers; title: string }) {
  const { t, i18n } = useTranslation();
  const fr = i18n.language.startsWith('fr');
  return <Paper variant="outlined" sx={{ p: 2 }}><Typography variant="subtitle1">{title}</Typography>{definition.questions.map(question => {
    const value = answers[question.id];
    const optionLabel = (id: string) => { const option = question.options?.find(item => item.id === id); return option ? fr && option.label_fr ? option.label_fr : option.label : id; };
    const text = value === undefined ? t('Not answered') : typeof value === 'boolean' ? t(value ? 'Yes' : 'No') : Array.isArray(value) ? value.map(optionLabel).join(', ') : typeof value === 'string' ? question.type === 'single_choice' ? optionLabel(value) : value : value.number;
    return <Typography key={question.id} sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}><strong>{fr && question.label_fr ? question.label_fr : question.label}: </strong>{text}</Typography>;
  })}</Paper>;
}

export function CustomerForms({ onLockedChange }: { onLockedChange: (locked: boolean) => void }) { return <FormTasks request={customerFetch} customer onLockedChange={onLockedChange}/>; }

import { useState } from 'react';
import { Alert, Button, Checkbox, FormControlLabel, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { I18nextProvider, useTranslation } from 'react-i18next';
import appI18n from '../i18n';
import type { Definition } from './FormTasks';
import { questionGroups } from './sections';
import { ChoiceField } from './ChoiceField';
import { TypedFormField, type FormAnswer } from './TypedFormField';

export function FormPreview({ name, definition }: { name: string; definition: Definition }) {
  // Keep preview language local: the surrounding portal can remount routes when
  // its language changes, which would otherwise discard the open preview.
  const [previewI18n] = useState(() => appI18n.cloneInstance({ lng: appI18n.language }));
  return <I18nextProvider i18n={previewI18n}><PreviewFields name={name} definition={definition}/></I18nextProvider>;
}
function PreviewFields({ name, definition }: { name: string; definition: Definition }) {
  const { t, i18n } = useTranslation();
  const [answers, setAnswers] = useState<Record<string, FormAnswer>>({});
  const fr = i18n.language.startsWith('fr');
  return <Stack spacing={2}>
    <Alert severity="info">{t('Preview only. Answers entered here are not saved.')}</Alert>
    <Stack direction="row"><Button onClick={() => void i18n.changeLanguage('en')}>EN</Button><Button onClick={() => void i18n.changeLanguage('fr')}>FR</Button></Stack>
    <Typography variant="h5">{name}</Typography>
    <Typography sx={{ whiteSpace: 'pre-wrap' }}>{fr && definition.instructions_fr ? definition.instructions_fr : definition.instructions}</Typography>
    {questionGroups(definition).map(group => <Stack key={group.section?.id ?? 'unsectioned'} spacing={2}>
      {group.section && <><Typography variant="h6">{fr && group.section.title_fr ? group.section.title_fr : group.section.title}</Typography><Typography sx={{ whiteSpace: 'pre-wrap' }}>{fr && group.section.description_fr ? group.section.description_fr : group.section.description}</Typography></>}
      {group.questions.map(q => {
        const label = fr && q.label_fr ? q.label_fr : q.label;
        const onChange = (value: FormAnswer) => setAnswers(current => ({ ...current, [q.id]: value }));
        return <Stack key={q.id}>
          {['single_choice', 'multiple_choice'].includes(q.type) ? <ChoiceField question={q} label={label} value={answers[q.id]} disabled={false} onChange={onChange}/>
            : ['date', 'phone', 'email'].includes(q.type) ? <TypedFormField question={q} label={label} value={answers[q.id]} disabled={false} onChange={onChange}/>
              : q.type === 'consent' ? <FormControlLabel label={label} required={q.required} control={<Checkbox checked={answers[q.id] === true} onChange={event => onChange(event.target.checked)}/>}/>
                : q.type === 'yes_no' ? <TextField select label={label} required={q.required} value={answers[q.id] === undefined ? '' : String(answers[q.id])} onChange={event => onChange(event.target.value === 'true')}><MenuItem value="true">{t('Yes')}</MenuItem><MenuItem value="false">{t('No')}</MenuItem></TextField>
                  : <TextField label={label} required={q.required} multiline value={answers[q.id] ?? ''} onChange={event => onChange(event.target.value)}/>}
        </Stack>;
      })}
    </Stack>)}
  </Stack>;
}

import { Button, Stack, TextField } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { Question } from './FormTasks';
export function ChoiceOptionsEditor({ question, disabled, onChange }: { question: Question; disabled: boolean; onChange: (changes: Partial<Question>) => void }) {
  const { t } = useTranslation();
  const options = question.options ?? [];
  return <Stack spacing={2}>{options.map((option, index) => <Stack key={option.id} spacing={1}>
    <TextField required label={t('Option {{number}} (English)', { number: index + 1 })} value={option.label} disabled={disabled} inputProps={{ maxLength: 190 }} onChange={event => onChange({ options: options.map(item => item.id === option.id ? { ...item, label: event.target.value } : item) })}/>
    <TextField label={t('Option {{number}} (French)', { number: index + 1 })} value={option.label_fr} disabled={disabled} inputProps={{ maxLength: 190 }} onChange={event => onChange({ options: options.map(item => item.id === option.id ? { ...item, label_fr: event.target.value } : item) })}/>
    <Button disabled={disabled || options.length <= 2} onClick={() => onChange({ options: options.filter(item => item.id !== option.id) })}>{t('Remove option')}</Button>
  </Stack>)}<Button disabled={disabled || options.length >= 20} onClick={() => onChange({ options: [...options, { id: `o${crypto.randomUUID().replaceAll('-', '').slice(0, 20)}`, label: '', label_fr: '' }] })}>{t('Add option')}</Button></Stack>;
}

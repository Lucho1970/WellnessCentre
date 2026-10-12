import { FormControlLabel, ValidationField } from '../shared/FormValidation';
import { Button, Checkbox, FormControl, FormLabel, Radio, RadioGroup, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { Question } from './FormTasks';
import type { FormAnswer } from './TypedFormField';
export function ChoiceField({ question, label, value, disabled, onChange }: { question: Question; label: string; value: FormAnswer | undefined; disabled: boolean; onChange: (value: FormAnswer) => void }) {
  const { t, i18n } = useTranslation();
  const text = (option: { label: string; label_fr: string }) => i18n.language.startsWith('fr') && option.label_fr ? option.label_fr : option.label;
  return <ValidationField name={`answers.${question.id}`}><FormControl component="fieldset" required={question.required} disabled={disabled}>
    <FormLabel component="legend">{label}</FormLabel>
    <Typography variant="body2">{t(question.type === 'single_choice' ? 'Choose one option.' : 'Select all that apply.')}</Typography>
    {question.type === 'single_choice' ? <><RadioGroup value={typeof value === 'string' ? value : ''} onChange={event => onChange(event.target.value)}>{question.options?.map(option => <FormControlLabel key={option.id} label={text(option)} value={option.id} control={<Radio disabled={disabled}/>}/>)}</RadioGroup>{!disabled && !question.required && <Button onClick={() => onChange('')}>{t('Clear selection')}</Button>}</>
      : <Stack>{question.options?.map(option => <FormControlLabel key={option.id} label={text(option)} control={<Checkbox disabled={disabled} checked={Array.isArray(value) && value.includes(option.id)} onChange={event => onChange(event.target.checked ? [...(Array.isArray(value) ? value : []), option.id] : (Array.isArray(value) ? value : []).filter(id => id !== option.id))}/>}/>)}</Stack>}
  </FormControl></ValidationField>;
}

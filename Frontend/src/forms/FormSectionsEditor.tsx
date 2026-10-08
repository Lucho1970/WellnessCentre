import { TextField } from '../shared/FormValidation';
import type { Dispatch, SetStateAction } from 'react';
import { Button, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { Definition } from './FormTasks';

export function FormSectionsEditor({ definition, setDefinition, disabled }: { definition: Definition; setDefinition: Dispatch<SetStateAction<Definition>>; disabled: boolean }) {
  const { t } = useTranslation();
  const sections = definition.sections ?? [];
  const move = (index: number, delta: number) => setDefinition(current => {
    const next = [...(current.sections ?? [])];
    [next[index], next[index + delta]] = [next[index + delta], next[index]];
    return { ...current, sections: next };
  });
  const remove = (id: string) => {
    if (!window.confirm(t('Remove this section? Its questions will move to Questions without a section.'))) return;
    setDefinition(current => ({ ...current, sections: current.sections?.filter(section => section.id !== id), questions: current.questions.map(question => {
      if (question.section_id !== id) return question;
      const { section_id: removed, ...rest } = question; void removed; return rest;
    }) }));
  };
  return <Stack spacing={2}>
    <Typography variant="h6">{t('Form sections')}</Typography>
    {sections.map((section, index) => <Paper key={section.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <TextField name="title" required fullWidth label={t('Section {{number}} title (English)', { number: index + 1 })} disabled={disabled} value={section.title} inputProps={{ maxLength: 190 }} onChange={event => setDefinition(current => ({ ...current, sections: current.sections?.map(item => item.id === section.id ? { ...item, title: event.target.value } : item) }))}/>
      <TextField name="title_fr" fullWidth label={t('Section {{number}} title (French)', { number: index + 1 })} disabled={disabled} value={section.title_fr} inputProps={{ maxLength: 190 }} onChange={event => setDefinition(current => ({ ...current, sections: current.sections?.map(item => item.id === section.id ? { ...item, title_fr: event.target.value } : item) }))}/>
      <TextField name="description" fullWidth multiline minRows={2} label={t('Section {{number}} description (English)', { number: index + 1 })} disabled={disabled} value={section.description ?? ''} inputProps={{ maxLength: 2000 }} onChange={event => setDefinition(current => ({ ...current, sections: current.sections?.map(item => item.id === section.id ? { ...item, description: event.target.value } : item) }))}/>
      <TextField name="description_fr" fullWidth multiline minRows={2} label={t('Section {{number}} description (French)', { number: index + 1 })} disabled={disabled} value={section.description_fr ?? ''} inputProps={{ maxLength: 2000 }} onChange={event => setDefinition(current => ({ ...current, sections: current.sections?.map(item => item.id === section.id ? { ...item, description_fr: event.target.value } : item) }))}/>
      <Stack direction="row" gap={1} flexWrap="wrap">
        <Button disabled={disabled || index === 0} onClick={() => move(index, -1)}>{t('Move section up')}</Button>
        <Button disabled={disabled || index === sections.length - 1} onClick={() => move(index, 1)}>{t('Move section down')}</Button>
        <Button disabled={disabled} onClick={() => remove(section.id)}>{t('Remove section')}</Button>
      </Stack>
    </Stack></Paper>)}
    <Button disabled={disabled || sections.length >= 15} onClick={() => setDefinition(current => ({ ...current, sections: [...(current.sections ?? []), { id: `s${crypto.randomUUID().replaceAll('-', '').slice(0, 20)}`, title: '', title_fr: '' }] }))}>{t('Add section')}</Button>
  </Stack>;
}

import type { Definition, Question } from './FormTasks';
export type FormSection = { id: string; title: string; title_fr: string; description?: string; description_fr?: string };
export function questionGroups(definition: Definition): { section?: FormSection; questions: Question[] }[] {
  const sections = definition.sections ?? [];
  const known = new Set(sections.map(section => section.id));
  const unsectioned = definition.questions.filter(question => !question.section_id || !known.has(question.section_id));
  return [
    ...(unsectioned.length || !sections.length ? [{ questions: unsectioned }] : []),
    ...sections.map(section => ({ section, questions: definition.questions.filter(question => question.section_id === section.id) }))
  ];
}

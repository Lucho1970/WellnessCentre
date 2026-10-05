import { useEffect, type ReactNode } from 'react';
import { Accordion, AccordionDetails, AccordionSummary, Box, Container, Grid, Link as MuiLink, Paper, Stack, Typography } from '@mui/material';
import { ChevronDown } from 'lucide-react';
import ReactMarkdown, { type Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { Link as RouterLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { localizedContent } from './content';

function safeUrl(value: string) {
  const url = value.trim();
  return /^(?:https:\/\/|mailto:|tel:|\/|#)/i.test(url) ? url : '';
}

function safeImageUrl(value: string) {
  const url = value.trim();
  return url.startsWith('/content-assets/') ? url : '';
}

const components: Components = {
  h1: ({ children }) => <Typography component="h1" variant="h3" mb={3}>{children}</Typography>,
  h2: ({ children }) => <Typography component="h2" variant="h5" mt={4} mb={1.5}>{children}</Typography>,
  h3: ({ children }) => <Typography component="h3" variant="h6" mt={3} mb={1}>{children}</Typography>,
  p: ({ children }) => <Typography component="p" color="text.secondary" sx={{ mb: 2, lineHeight: 1.75 }}>{children}</Typography>,
  ul: ({ children }) => <Box component="ul" sx={{ mt: 0, mb: 2, pl: 3 }}>{children}</Box>,
  ol: ({ children }) => <Box component="ol" sx={{ mt: 0, mb: 2, pl: 3 }}>{children}</Box>,
  li: ({ children }) => <Typography component="li" color="text.secondary" sx={{ mb: 0.75, lineHeight: 1.65 }}>{children}</Typography>,
  blockquote: ({ children }) => <Paper component="blockquote" variant="outlined" sx={{ m: 0, my: 3, px: 3, py: 2, borderLeft: 4, borderLeftColor: 'primary.main' }}>{children}</Paper>,
  a: ({ href = '', children }) => {
    const destination = safeUrl(href);
    if (!destination) return <>{children}</>;
    return destination.startsWith('/')
      ? <MuiLink component={RouterLink} to={destination}>{children}</MuiLink>
      : <MuiLink href={destination} {...(destination.startsWith('https://') ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>{children}</MuiLink>;
  },
  img: ({ src = '', alt = '' }) => {
    const source = safeImageUrl(src);
    return source ? <Box component="img" src={source} alt={alt} sx={{ display: 'block', width: '100%', maxHeight: 520, objectFit: 'cover', borderRadius: 2, my: 3 }} /> : null;
  },
  hr: () => <Box component="hr" sx={{ border: 0, borderTop: 1, borderColor: 'divider', my: 4 }} />,
};

const embeddedPageComponents: Components = {
  ...components,
  h1: ({ children }) => <Typography component="h2" variant="h3" mb={3}>{children}</Typography>,
  h2: ({ children }) => <Typography component="h3" variant="h5" mt={4} mb={1.5}>{children}</Typography>,
  h3: ({ children }) => <Typography component="h4" variant="h6" mt={3} mb={1}>{children}</Typography>,
};

function Body({ children, embeddedPage = false }: { children: string; embeddedPage?: boolean }) {
  return <ReactMarkdown remarkPlugins={[remarkGfm]} components={embeddedPage ? embeddedPageComponents : components} urlTransform={safeUrl}>{children}</ReactMarkdown>;
}

function MissingContent({ children }: { children: ReactNode }) {
  return <Container maxWidth="md" sx={{ py: { xs: 5, md: 8 } }}><Typography variant="h4">{children}</Typography></Container>;
}

export function ContentPage({ contentKey }: { contentKey: string }) {
  const { i18n, t } = useTranslation();
  const { config } = useClinicConfig();
  const document = localizedContent(i18n.resolvedLanguage, contentKey);
  useEffect(() => {
    if (!document) return;
    const previousTitle = window.document.title;
    const title = `${document.metadata.title} | ${config.name}`;
    let description = window.document.querySelector<HTMLMetaElement>('meta[name="description"]');
    const created = !description;
    if (!description) {
      description = window.document.createElement('meta');
      description.name = 'description';
      window.document.head.append(description);
    }
    const previousDescription = description.content;
    window.document.title = title;
    description.content = document.metadata.description;
    return () => {
      window.document.title = previousTitle;
      if (created) description.remove(); else description.content = previousDescription;
    };
  }, [config.name, document]);
  if (!document) return <MissingContent>{t('Content unavailable')}</MissingContent>;
  return <Container maxWidth="md" sx={{ py: { xs: 5, md: 8 } }}><Body>{document.body}</Body></Container>;
}

export function ContentSection({ contentKey, presentation }: { contentKey: string; presentation?: 'split' | 'faq' }) {
  const { i18n } = useTranslation();
  const document = localizedContent(i18n.resolvedLanguage, contentKey);
  if (!document) return null;
  const embeddedPage = contentKey.startsWith('pages/');
  const [intro, ...blocks] = document.body.split(/\r?\n(?=## )/);
  if (presentation === 'split') {
    return <Box py={{ xs: 7, md: 12 }}><Container maxWidth="lg"><Grid container spacing={{ xs: 4, md: 9 }} alignItems="start">
      <Grid size={{ xs: 12, md: 5 }}><Box className="editorial-intro"><Body embeddedPage={embeddedPage}>{intro}</Body></Box></Grid>
      <Grid size={{ xs: 12, md: 7 }}><Paper className="editorial-detail" variant="outlined" sx={{ p: { xs: 3, md: 5 } }}><Body embeddedPage={embeddedPage}>{blocks.join('\n')}</Body></Paper></Grid>
    </Grid></Container></Box>;
  }
  if (presentation === 'faq') {
    const questions = blocks.map(block => {
      const newline = block.indexOf('\n');
      return { question: block.slice(3, newline < 0 ? undefined : newline).trim(), answer: newline < 0 ? '' : block.slice(newline + 1).trim() };
    });
    return <Box py={{ xs: 7, md: 12 }}><Container maxWidth="lg"><Grid container spacing={{ xs: 3, md: 8 }} alignItems="start">
      <Grid size={{ xs: 12, md: 4 }}><Body embeddedPage={embeddedPage}>{intro}</Body></Grid>
      <Grid size={{ xs: 12, md: 8 }}><Stack spacing={1.5}>{questions.map((item, index) => <Accordion key={`${index}-${item.question}`} disableGutters elevation={0} defaultExpanded={index === 0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: '14px !important', '&:before': { display: 'none' } }}>
        <AccordionSummary expandIcon={<ChevronDown size={20} />} aria-controls={`faq-answer-${index}`} id={`faq-question-${index}`} sx={{ px: { xs: 2.5, md: 3 }, py: 0.5 }}><Typography component="span" variant="h6">{item.question}</Typography></AccordionSummary>
        <AccordionDetails id={`faq-answer-${index}`} sx={{ px: { xs: 2.5, md: 3 }, pb: 2.5 }}><Body>{item.answer}</Body></AccordionDetails>
      </Accordion>)}</Stack></Grid>
    </Grid></Container></Box>;
  }
  return <Box py={{ xs: 7, md: 10 }}><Container maxWidth="lg"><Body embeddedPage={embeddedPage}>{document.body}</Body></Container></Box>;
}

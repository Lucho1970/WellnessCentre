import { useEffect, type ReactNode } from 'react';
import { Box, Container, Link as MuiLink, Paper, Typography } from '@mui/material';
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

function Body({ children }: { children: string }) {
  return <ReactMarkdown remarkPlugins={[remarkGfm]} components={components} urlTransform={safeUrl}>{children}</ReactMarkdown>;
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

export function ContentSection({ contentKey }: { contentKey: string }) {
  const { i18n } = useTranslation();
  const document = localizedContent(i18n.resolvedLanguage, contentKey);
  if (!document) return null;
  return <Box component="section" py={{ xs: 5, md: 7 }}><Container maxWidth="lg"><Body>{document.body}</Body></Container></Box>;
}

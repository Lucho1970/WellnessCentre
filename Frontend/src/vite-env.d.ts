/// <reference types="vite/client" />
declare const __APP_SURFACE__: 'public' | 'portal';

interface ImportMetaEnv {
  readonly VITE_CUSTOMER_ENTRA_TENANT_ID?: string;
  readonly VITE_CUSTOMER_ENTRA_SUBDOMAIN?: string;
  readonly VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID?: string;
  readonly VITE_CUSTOMER_ENTRA_API_CLIENT_ID?: string;
  readonly VITE_ENTRA_TENANT_ID?: string;
  readonly VITE_ENTRA_SPA_CLIENT_ID?: string;
  readonly VITE_ENTRA_API_CLIENT_ID?: string;
  readonly VITE_API_BASE_URL?: string;
  readonly VITE_PUBLIC_URL?: string;
  readonly VITE_PORTAL_URL?: string;
  readonly VITE_ENTRA_REDIRECT_URI?: string;
}

interface ImportMeta { readonly env: ImportMetaEnv; }

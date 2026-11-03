import {defineConfig} from '@playwright/test';
if(!process.env.SERVER_SESSION_BROWSER_RUNTIME || !process.env.SERVER_SESSION_BROWSER_API)throw new Error('Run only through disposable SQL runner');
export default defineConfig({
  testDir:'./tests',testMatch:'server-session-integration.spec.ts',workers:1,fullyParallel:false,timeout:45000,expect:{timeout:15000},
  outputDir:'./test-results/server-session',reporter:[['list'],['json',{outputFile:'./test-results/server-session-report.json'}]],
  use:{serviceWorkers:'block',trace:'off'},
  projects:[{name:'edge',use:{browserName:'chromium',channel:'msedge'}},{name:'firefox',use:{browserName:'firefox'}}],
  webServer:{command:'node node_modules/vite/bin/vite.js --config vite.server-session.config.ts',url:'http://127.0.0.1:5198/tests/server-session/index.html',reuseExistingServer:false,env:{
    VITE_ENTRA_TENANT_ID:'11111111-1111-4111-8111-111111111111',VITE_ENTRA_SPA_CLIENT_ID:'22222222-2222-4222-8222-222222222221',VITE_ENTRA_API_CLIENT_ID:'33333333-3333-4333-8333-333333333331',VITE_ENTRA_REDIRECT_URI:'https://clinic-a.session.test/',
    VITE_STAFF_EXTERNAL_TENANT_ID:'11111111-1111-4111-8111-111111111111',VITE_STAFF_EXTERNAL_SUBDOMAIN:'teststaff',VITE_STAFF_EXTERNAL_SPA_CLIENT_ID:'22222222-2222-4222-8222-222222222222',VITE_STAFF_EXTERNAL_API_CLIENT_ID:'33333333-3333-4333-8333-333333333332',
    VITE_STAFF_EXTERNAL_AUTH_ENABLED:'true',VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED:'true',VITE_STAFF_INVITATIONS_ENABLED:'false',VITE_PRACTITIONER_APPLICATIONS_ENABLED:'false',VITE_GOOGLE_MAPS_BROWSER_API_KEY:'',
    VITE_CUSTOMER_ENTRA_TENANT_ID:'11111111-1111-4111-8111-111111111111',VITE_CUSTOMER_ENTRA_SUBDOMAIN:'testclients',VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID:'22222222-2222-4222-8222-222222222223',VITE_CUSTOMER_ENTRA_API_CLIENT_ID:'33333333-3333-4333-8333-333333333333',
  }},
});

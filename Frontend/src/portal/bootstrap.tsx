export async function bootstrap() {
  const clientPath = `${import.meta.env.BASE_URL}client`;
  if (window.location.pathname === clientPath || window.location.pathname.startsWith(`${clientPath}/`)) {
    await (await import('../customer/bootstrap')).bootstrap();
    return;
  }
  await (await import('./staffBootstrap')).bootstrap();
}

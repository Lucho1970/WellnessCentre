export async function bootstrap() {
  if (window.location.pathname === `${import.meta.env.BASE_URL}login`) {
    window.location.replace(`${import.meta.env.BASE_URL}client`);
    return;
  }
  if (window.location.pathname === `${import.meta.env.BASE_URL}client/session`) {
    await (await import('../customer/sessionBridge')).bootstrap();
    return;
  }
  const clientPath = `${import.meta.env.BASE_URL}client`;
  if (window.location.pathname === clientPath || window.location.pathname.startsWith(`${clientPath}/`)) {
    await (await import('../customer/bootstrap')).bootstrap();
    return;
  }
  await (await import('./staffBootstrap')).bootstrap();
}

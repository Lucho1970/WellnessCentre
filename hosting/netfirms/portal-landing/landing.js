(() => {
  const root = document.documentElement;
  const buttons = [...document.querySelectorAll('[data-set-language]')];
  const choose = language => {
    const selected = language === 'fr' ? 'fr' : 'en';
    root.lang = selected;
    root.dataset.language = selected;
    document.title = selected === 'fr' ? 'Portails Copihue' : 'Copihue Portals';
    buttons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.setLanguage === selected)));
  };
  buttons.forEach(button => button.addEventListener('click', () => choose(button.dataset.setLanguage)));
  choose(new URLSearchParams(window.location.search).get('lang') || navigator.language);
})();

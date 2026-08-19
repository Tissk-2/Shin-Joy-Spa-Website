export const initialHeaderMenuState = Object.freeze({
  mobileMenuOpen: false,
  accountMenuOpen: false,
});

export function transitionHeaderMenuState(state, event = {}, onRestoreAccountFocus = () => {}) {
  const current = {
    mobileMenuOpen: Boolean(state?.mobileMenuOpen),
    accountMenuOpen: Boolean(state?.accountMenuOpen),
  };
  const restoreAccountFocus = event.restoreAccountFocus !== false;
  let nextState = current;
  let shouldRestoreAccountFocus = false;

  switch (event.type) {
    case 'set-mobile-menu': {
      const mobileMenuOpen = Boolean(event.open);
      nextState = {
        mobileMenuOpen,
        accountMenuOpen: mobileMenuOpen ? current.accountMenuOpen : false,
      };
      break;
    }
    case 'set-account-menu': {
      const accountMenuOpen = Boolean(event.open);
      shouldRestoreAccountFocus = !accountMenuOpen && current.accountMenuOpen && restoreAccountFocus;
      nextState = { ...current, accountMenuOpen };
      break;
    }
    case 'outside-pointer':
      nextState = { ...current, accountMenuOpen: false };
      break;
    case 'escape':
      if (current.accountMenuOpen) {
        nextState = { ...current, accountMenuOpen: false };
        shouldRestoreAccountFocus = true;
      } else {
        nextState = { mobileMenuOpen: false, accountMenuOpen: false };
      }
      break;
    case 'route-change':
    case 'logout':
      nextState = { mobileMenuOpen: false, accountMenuOpen: false };
      break;
    default:
      nextState = { ...current };
  }

  if (shouldRestoreAccountFocus) onRestoreAccountFocus();
  return Object.freeze(nextState);
}

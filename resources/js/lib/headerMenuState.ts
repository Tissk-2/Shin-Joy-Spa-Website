export interface HeaderMenuState {
  readonly mobileMenuOpen: boolean;
  readonly accountMenuOpen: boolean;
}

export interface HeaderMenuEvent {
  type?:
    | "set-mobile-menu"
    | "set-account-menu"
    | "outside-pointer"
    | "escape"
    | "route-change"
    | "logout";
  open?: boolean;
  restoreAccountFocus?: boolean;
}

export const initialHeaderMenuState: Readonly<HeaderMenuState> = Object.freeze({
  mobileMenuOpen: false,
  accountMenuOpen: false,
});

export function accountInitial(name: string | null | undefined): string {
  return Array.from(name?.trim() ?? "")[0]?.toLocaleUpperCase() ?? "?";
}

export function transitionHeaderMenuState(
  state: Partial<HeaderMenuState> | null | undefined,
  event: HeaderMenuEvent = {},
  onRestoreAccountFocus: () => void = () => undefined,
): Readonly<HeaderMenuState> {
  const current: HeaderMenuState = {
    mobileMenuOpen: Boolean(state?.mobileMenuOpen),
    accountMenuOpen: Boolean(state?.accountMenuOpen),
  };
  const restoreAccountFocus =
    !("restoreAccountFocus" in event) || event.restoreAccountFocus !== false;
  let nextState: HeaderMenuState = { ...current };
  let shouldRestoreAccountFocus = false;

  switch (event.type) {
    case "set-mobile-menu": {
      const mobileMenuOpen = Boolean(event.open);
      nextState = {
        mobileMenuOpen,
        accountMenuOpen: mobileMenuOpen ? current.accountMenuOpen : false,
      };
      break;
    }
    case "set-account-menu": {
      const accountMenuOpen = Boolean(event.open);
      shouldRestoreAccountFocus =
        !accountMenuOpen && current.accountMenuOpen && restoreAccountFocus;
      nextState = { ...current, accountMenuOpen };
      break;
    }
    case "outside-pointer":
      nextState = { ...current, accountMenuOpen: false };
      break;
    case "escape":
      if (current.accountMenuOpen) {
        nextState = { ...current, accountMenuOpen: false };
        shouldRestoreAccountFocus = true;
      } else {
        nextState = { mobileMenuOpen: false, accountMenuOpen: false };
      }
      break;
    case "route-change":
    case "logout":
      nextState = { mobileMenuOpen: false, accountMenuOpen: false };
      break;
  }

  if (shouldRestoreAccountFocus) {
    onRestoreAccountFocus();
  }

  return Object.freeze(nextState);
}

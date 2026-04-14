type Params = Record<string, string | number>;

export const t = (key: string, fallback: string, params?: Params): string => {
  if (typeof window === "undefined" || !window.ss?.i18n) {
    return fallback;
  }
  return window.ss.i18n._t(key, fallback, params);
};

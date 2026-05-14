export function isBrowserOnline() {
  if (typeof navigator === "undefined") {
    return true;
  }

  return navigator.onLine;
}

export function isOfflineError(error) {
  return !isBrowserOnline() || error instanceof TypeError;
}

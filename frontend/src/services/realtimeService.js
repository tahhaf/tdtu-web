function normalizeWebSocketUrl(url) {
  if (!url) {
    return "";
  }

  return url
    .replace(/^http:\/\//, "ws://")
    .replace(/^https:\/\//, "wss://")
    .replace(/\/$/, "");
}

export function getWebSocketUrl() {
  const configuredUrl = normalizeWebSocketUrl(import.meta.env.VITE_WS_URL || "");

  if (configuredUrl) {
    return configuredUrl;
  }

  const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || "";
  const wsPort = import.meta.env.VITE_WS_PORT || "8081";

  let protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
  let host = window.location.hostname;

  if (apiBaseUrl.startsWith("http")) {
    try {
      const apiUrl = new URL(apiBaseUrl);
      protocol = apiUrl.protocol === "https:" ? "wss:" : "ws:";
      host = apiUrl.hostname;
    } catch {
      // Keep the current location fallback.
    }
  }

  return `${protocol}//${host}${wsPort ? `:${wsPort}` : ""}`;
}

export function createWebSocket() {
  return new WebSocket(getWebSocketUrl());
}

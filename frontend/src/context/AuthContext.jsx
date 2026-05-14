import { useCallback, useEffect, useMemo, useState } from "react";
import authService from "../services/authService";
import AuthContext from "./authContext";
import { isOfflineError } from "../services/offlineUtils";

const AUTH_CACHE_KEY = "notemate-auth-user";

function readCachedUser() {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(AUTH_CACHE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function writeCachedUser(user) {
  if (typeof window === "undefined") {
    return;
  }

  if (!user) {
    window.localStorage.removeItem(AUTH_CACHE_KEY);
    return;
  }

  window.localStorage.setItem(AUTH_CACHE_KEY, JSON.stringify(user));
}

function applyUserPreferences(user) {
  if (typeof document === "undefined") {
    return;
  }

  if (user?.preferences?.theme === "dark") {
    document.body.classList.add("dark-theme");
  } else {
    document.body.classList.remove("dark-theme");
  }

  if (user?.preferences?.fontSize) {
    document.documentElement.style.setProperty("--global-font-size", `${user.preferences.fontSize}px`);
  } else {
    document.documentElement.style.removeProperty("--global-font-size");
  }

  if (user?.preferences?.noteColor) {
    document.documentElement.setAttribute("data-note-color", user.preferences.noteColor);
  } else {
    document.documentElement.removeAttribute("data-note-color");
  }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [isAuthLoading, setIsAuthLoading] = useState(true);

  const isAuthenticated = !!user;

  const storeUser = useCallback((nextUser) => {
    setUser(nextUser);
    writeCachedUser(nextUser);
  }, []);

  const fetchMe = useCallback(async () => {
    try {
      const response = await authService.getMe();
      const userData = response?.data ?? response ?? null;
      storeUser(userData);
      return userData;
    } catch (error) {
      if (isOfflineError(error)) {
        const cachedUser = readCachedUser();

        if (cachedUser) {
          setUser(cachedUser);
          return cachedUser;
        }
      }

      storeUser(null);
      throw error;
    }
  }, [storeUser]);

  const login = useCallback(
    async (payload) => {
      const response = await authService.login(payload);

      try {
        const me = await fetchMe();
        return {
          success: true,
          user: me,
          response,
        };
      } catch (error) {
        storeUser(null);
        throw error;
      }
    },
    [fetchMe, storeUser]
  );

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } finally {
      storeUser(null);
    }
  }, [storeUser]);

  const refreshAuth = useCallback(async () => {
    setIsAuthLoading(true);
    try {
      await fetchMe();
    } catch {
      storeUser(readCachedUser());
    } finally {
      setIsAuthLoading(false);
    }
  }, [fetchMe, storeUser]);

  useEffect(() => {
    refreshAuth();
  }, [refreshAuth]);

  useEffect(() => {
    applyUserPreferences(user);
  }, [user]);

  const value = useMemo(
    () => ({
      user,
      isAuthenticated,
      isAuthLoading,
      login,
      logout,
      fetchMe,
      refreshAuth,
    }),
    [user, isAuthenticated, isAuthLoading, login, logout, fetchMe, refreshAuth]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

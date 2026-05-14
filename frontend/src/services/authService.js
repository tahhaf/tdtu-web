function getApiBaseUrl() {
  const configuredUrl = (import.meta.env.VITE_API_BASE_URL || "").trim();

  if (!configuredUrl || /^wss?:\/\//i.test(configuredUrl)) {
    return "/api";
  }

  return configuredUrl.replace(/\/$/, "");
}

const API_BASE_URL = getApiBaseUrl();

export async function request(url, options = {}) {
  const response = await fetch(`${API_BASE_URL}${url}`, {
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    },
    ...options,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const error = new Error(data?.message || "Request failed");
    error.status = response.status;
    error.data = data;
    throw error;
  }

  return { data };
}

const authService = {
  register(payload) {
    return request("/auth/register", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  activate(payload) {
    return request("/auth/activate", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  resendActivation() {
    return request("/auth/resend-activation", {
      method: "POST",
    });
  },

  login(payload) {
    return request("/auth/login", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  logout() {
    return request("/auth/logout", {
      method: "POST",
    });
  },

  forgotPassword(payload) {
    return request("/auth/forgot-password", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  resetPassword(payload) {
    return request("/auth/reset-password", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  verifyResetToken(payload) {
    return request("/auth/verify-reset-token", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  verifyResetOtp(payload) {
    return request("/auth/verify-reset-otp", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  resetPasswordOtp(payload) {
    return request("/auth/reset-password-otp", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  getMe() {
    return request("/users/me", {
      method: "GET",
    });
  },

  updateProfile(payload) {
    return request("/users/me/profile", {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },

  updatePassword(payload) {
    return request("/users/me/password", {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },

  updatePreferences(payload) {
    return request("/users/me/preferences", {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
};

export default authService;

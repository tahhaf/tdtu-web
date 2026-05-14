import { useState, useEffect } from "react";
import authService from "../services/authService";
import useAuth from "../hooks/useAuth";

export default function Profile() {
  const { user, fetchMe } = useAuth();

  const [profileData, setProfileData] = useState({
    displayName: user?.displayName || "",
    avatarUrl: user?.avatarUrl || "",
  });

  const [passwordData, setPasswordData] = useState({
    currentPassword: "",
    newPassword: "",
    confirmPassword: "",
  });

  const [preferencesData, setPreferencesData] = useState({
    theme: user?.preferences?.theme || "light",
    fontSize: user?.preferences?.fontSize || 14,
    noteColor: user?.preferences?.noteColor || "default",
  });

  const [messages, setMessages] = useState({
    profile: { success: "", error: "" },
    password: { success: "", error: "" },
    preferences: { success: "", error: "" },
  });

  useEffect(() => {
    if (user) {
      setProfileData({
        displayName: user.displayName || "",
        avatarUrl: user.avatarUrl || "",
      });
      setPreferencesData({
        theme: user.preferences?.theme || "light",
        fontSize: user.preferences?.fontSize || 14,
        noteColor: user.preferences?.noteColor || "default",
      });
    }
  }, [user]);

  const handleProfileChange = (event) => {
    setProfileData({ ...profileData, [event.target.name]: event.target.value });
  };

  const handlePasswordChange = (event) => {
    setPasswordData({ ...passwordData, [event.target.name]: event.target.value });
  };

  const handlePreferencesChange = (event) => {
    setPreferencesData({ ...preferencesData, [event.target.name]: event.target.value });
  };

  const clearMessages = (section) => {
    setMessages((prev) => ({
      ...prev,
      [section]: { success: "", error: "" },
    }));
  };

  const handleAvatarUpload = (event) => {
    const file = event.target.files[0];
    if (file && file.type.startsWith("image/")) {
      const reader = new FileReader();
      reader.onload = (loadEvent) => {
        setProfileData({ ...profileData, avatarUrl: loadEvent.target.result });
      };
      reader.readAsDataURL(file);
    }
  };

  const handleUpdateProfile = async (event) => {
    event.preventDefault();
    clearMessages("profile");

    try {
      await authService.updateProfile(profileData);
      await fetchMe();
      setMessages((prev) => ({
        ...prev,
        profile: { success: "Profile updated successfully.", error: "" },
      }));
    } catch (error) {
      setMessages((prev) => ({
        ...prev,
        profile: { success: "", error: error?.data?.message || "Failed to update profile." },
      }));
    }
  };

  const handleChangePassword = async (event) => {
    event.preventDefault();
    clearMessages("password");

    try {
      await authService.updatePassword(passwordData);
      setMessages((prev) => ({
        ...prev,
        password: { success: "Password changed successfully.", error: "" },
      }));
      setPasswordData({ currentPassword: "", newPassword: "", confirmPassword: "" });
    } catch (error) {
      setMessages((prev) => ({
        ...prev,
        password: { success: "", error: error?.data?.message || "Failed to change password." },
      }));
    }
  };

  const handleUpdatePreferences = async (event) => {
    event.preventDefault();
    clearMessages("preferences");

    try {
      await authService.updatePreferences(preferencesData);
      await fetchMe();
      setMessages((prev) => ({
        ...prev,
        preferences: { success: "Preferences updated successfully.", error: "" },
      }));
    } catch (error) {
      setMessages((prev) => ({
        ...prev,
        preferences: { success: "", error: error?.data?.message || "Failed to update preferences." },
      }));
    }
  };

  return (
    <div className="container py-5">
      <h1 className="h3 mb-4">Profile & Settings</h1>

      <div className="row g-4">
        <div className="col-12 col-lg-6">
          <div className="card shadow-sm border-0 h-100">
            <div className="card-header bg-white border-bottom">
              <h5 className="mb-0">Edit Profile</h5>
            </div>
            <div className="card-body">
              {messages.profile.success && <div className="alert alert-success">{messages.profile.success}</div>}
              {messages.profile.error && <div className="alert alert-danger">{messages.profile.error}</div>}

              <form onSubmit={handleUpdateProfile}>
                <div className="mb-4 text-center">
                  <div className="position-relative d-inline-block">
                    {profileData.avatarUrl ? (
                      <img
                        src={profileData.avatarUrl}
                        alt="Avatar"
                        className="rounded-circle border bg-light"
                        style={{ width: "120px", height: "120px", objectFit: "cover" }}
                      />
                    ) : (
                      <div
                        className="rounded-circle bg-light d-flex align-items-center justify-content-center border"
                        style={{ width: "120px", height: "120px" }}
                      >
                        <span className="opacity-75 fs-1">{"\uD83D\uDC64"}</span>
                      </div>
                    )}
                    <label
                      className="btn btn-sm btn-dark position-absolute bottom-0 end-0 rounded-circle"
                      style={{ width: "32px", height: "32px", padding: "4px" }}
                    >
                      {"\uD83D\uDCF7"}
                      <input type="file" className="d-none" accept="image/*" onChange={handleAvatarUpload} />
                    </label>
                  </div>
                </div>

                <div className="mb-3">
                  <label className="form-label">Email</label>
                  <input type="text" className="form-control bg-light" value={user?.email || ""} disabled />
                </div>
                <div className="mb-4">
                  <label className="form-label">Display Name</label>
                  <input type="text" className="form-control" name="displayName" value={profileData.displayName} onChange={handleProfileChange} required />
                </div>
                <button type="submit" className="btn btn-primary w-100">
                  Update Profile
                </button>
              </form>
            </div>
          </div>
        </div>

        <div className="col-12 col-lg-6">
          <div className="card shadow-sm border-0 h-100">
            <div className="card-header bg-white border-bottom">
              <h5 className="mb-0">Change Password</h5>
            </div>
            <div className="card-body">
              {messages.password.success && <div className="alert alert-success">{messages.password.success}</div>}
              {messages.password.error && <div className="alert alert-danger">{messages.password.error}</div>}

              <form onSubmit={handleChangePassword}>
                <div className="mb-3">
                  <label className="form-label">Current Password</label>
                  <input type="password" className="form-control" name="currentPassword" value={passwordData.currentPassword} onChange={handlePasswordChange} required />
                </div>
                <div className="mb-3">
                  <label className="form-label">New Password</label>
                  <input type="password" className="form-control" name="newPassword" value={passwordData.newPassword} onChange={handlePasswordChange} required />
                </div>
                <div className="mb-4">
                  <label className="form-label">Confirm New Password</label>
                  <input type="password" className="form-control" name="confirmPassword" value={passwordData.confirmPassword} onChange={handlePasswordChange} required />
                </div>
                <button type="submit" className="btn btn-primary">
                  Change Password
                </button>
              </form>
            </div>
          </div>
        </div>

        <div className="col-12">
          <div className="card shadow-sm border-0">
            <div className="card-header bg-white border-bottom">
              <h5 className="mb-0">Preferences</h5>
            </div>
            <div className="card-body">
              {messages.preferences.success && <div className="alert alert-success">{messages.preferences.success}</div>}
              {messages.preferences.error && <div className="alert alert-danger">{messages.preferences.error}</div>}

              <form onSubmit={handleUpdatePreferences} className="row g-3">
                <div className="col-md-4">
                  <label className="form-label">Theme</label>
                  <select className="form-select" name="theme" value={preferencesData.theme} onChange={handlePreferencesChange}>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                  </select>
                </div>
                <div className="col-md-4">
                  <label className="form-label">Font Size</label>
                  <input type="number" className="form-control" name="fontSize" value={preferencesData.fontSize} onChange={handlePreferencesChange} min="10" max="36" />
                </div>
                <div className="col-md-4">
                  <label className="form-label">Default Note Color</label>
                  <select className="form-select" name="noteColor" value={preferencesData.noteColor} onChange={handlePreferencesChange}>
                    <option value="default">Default</option>
                    <option value="blue">Blue</option>
                    <option value="green">Green</option>
                    <option value="yellow">Yellow</option>
                  </select>
                </div>
                <div className="col-12 mt-4">
                  <button type="submit" className="btn btn-primary">
                    Save Preferences
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

import { useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import useAuth from "../hooks/useAuth";
import authService from "../services/authService";

export default function Register() {
  const navigate = useNavigate();
  const { fetchMe, isAuthenticated } = useAuth();

  const [formData, setFormData] = useState({
    email: "",
    displayName: "",
    password: "",
    confirmPassword: "",
  });

  const [errorMessage, setErrorMessage] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleChange = (event) => {
    const { name, value } = event.target;

    setFormData((prev) => ({
      ...prev,
      [name]: value,
    }));
  };

  const validateForm = () => {
    if (!formData.email.trim()) {
      return "Email is required.";
    }

    if (!formData.displayName.trim()) {
      return "Display name is required.";
    }

    if (!formData.password.trim()) {
      return "Password is required.";
    }

    if (!formData.confirmPassword.trim()) {
      return "Please confirm your password.";
    }

    if (formData.password !== formData.confirmPassword) {
      return "Password and confirm password do not match.";
    }

    return "";
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrorMessage("");

    const validationError = validateForm();
    if (validationError) {
      setErrorMessage(validationError);
      return;
    }

    setIsSubmitting(true);

    try {
      await authService.register(formData);
      await fetchMe();
      navigate("/", { replace: true });
      setTimeout(() => {
        authService.resendActivation().catch(() => {});
      }, 0);
    } catch (error) {
      setErrorMessage(error?.data?.message || error.message || "Register failed");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isAuthenticated) {
    return <Navigate to="/" replace />;
  }

  return (
    <div className="auth-page">
      <div className="card shadow-sm auth-card">
        <div className="card-body auth-card-body">
          <div className="text-center mb-4">
            <h1 className="auth-title">Create an account</h1>
            <p className="auth-subtitle">Sign up to start using your notes app</p>
          </div>
          {errorMessage && (
            <div className="alert alert-danger py-2" role="alert">
              {errorMessage}
            </div>
          )}
          <form onSubmit={handleSubmit} noValidate>
            <div className="mb-3">
              <label htmlFor="email" className="form-label">
                Email
              </label>
              <input
                id="email"
                name="email"
                type="email"
                className="form-control"
                value={formData.email}
                onChange={handleChange}
                placeholder="Enter your email"
                autoComplete="email"
              />
            </div>

            <div className="mb-3">
              <label htmlFor="displayName" className="form-label">
                Display name
              </label>
              <input
                id="displayName"
                name="displayName"
                type="text"
                className="form-control"
                value={formData.displayName}
                onChange={handleChange}
                placeholder="Enter your display name"
                autoComplete="nickname"
              />
            </div>

            <div className="mb-3">
              <label htmlFor="password" className="form-label">
                Password
              </label>
              <input
                id="password"
                name="password"
                type="password"
                className="form-control"
                value={formData.password}
                onChange={handleChange}
                placeholder="Enter your password"
                autoComplete="new-password"
              />
            </div>

            <div className="mb-4">
              <label htmlFor="confirmPassword" className="form-label">
                Confirm password
              </label>
              <input
                id="confirmPassword"
                name="confirmPassword"
                type="password"
                className="form-control"
                value={formData.confirmPassword}
                onChange={handleChange}
                placeholder="Confirm your password"
                autoComplete="new-password"
              />
            </div>

            <button
              type="submit"
              className="btn btn-primary w-100"
              disabled={isSubmitting}
            >
              {isSubmitting ? "Signing Up..." : "Sign Up"}
            </button>
          </form>

          <div className="auth-switch mt-4">
            <span className="text-muted">Already have an account? </span>
            <button
              type="button"
              className="btn btn-link text-decoration-none auth-link-btn"
              onClick={() => navigate("/login")}
            >
              Sign In
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

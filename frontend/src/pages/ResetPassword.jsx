import { useState, useEffect } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import authService from "../services/authService";

export default function ResetPassword() {
  const navigate = useNavigate();
  const location = useLocation();
  const queryParams = new URLSearchParams(location.search);
  const urlToken = queryParams.get("token");
  const email = location.state?.email || "";

  const [step, setStep] = useState(urlToken ? 2 : 1);
  const [formData, setFormData] = useState({
    tokenOrOtp: urlToken || "",
    password: "",
    confirmPassword: "",
  });

  const [errorMessage, setErrorMessage] = useState("");
  const [successMessage, setSuccessMessage] = useState(
    location.state?.successMessage || ""
  );
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isResetSuccessful, setIsResetSuccessful] = useState(false);
  const [isResending, setIsResending] = useState(false);
  const [isVerifyingAccess, setIsVerifyingAccess] = useState(!!urlToken);
  const [isResetAccessVerified, setIsResetAccessVerified] = useState(false);

  useEffect(() => {
    if (!email && !urlToken) {
      navigate("/forgot-password", { replace: true });
      return;
    }

    if (!urlToken) {
      return;
    }

    let isMounted = true;

    const verifyToken = async () => {
      setIsVerifyingAccess(true);
      setErrorMessage("");

      try {
        await authService.verifyResetToken({ token: urlToken });

        if (!isMounted) {
          return;
        }

        setIsResetAccessVerified(true);
        setStep(2);
        setSuccessMessage("Reset link verified. Create a new password.");
      } catch (error) {
        if (!isMounted) {
          return;
        }

        setIsResetAccessVerified(false);
        setErrorMessage(error?.data?.message || error.message || "Invalid or expired reset link.");
      } finally {
        if (isMounted) {
          setIsVerifyingAccess(false);
        }
      }
    };

    verifyToken();

    return () => {
      isMounted = false;
    };
  }, [email, urlToken, navigate]);

  const handleChange = (event) => {
    const { name, value } = event.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleVerifyOtp = async (e) => {
    e.preventDefault();
    setErrorMessage("");
    setSuccessMessage("");

    if (!formData.tokenOrOtp.trim()) {
      return setErrorMessage("Please enter the verification code.");
    }

    if (!email) {
      return setErrorMessage("Please request a new reset email first.");
    }

    setIsSubmitting(true);

    try {
      await authService.verifyResetOtp({
        email: email.toLowerCase().trim(),
        otp: formData.tokenOrOtp.trim(),
      });

      setIsResetAccessVerified(true);
      setStep(2);
      setSuccessMessage("Verification code accepted. Create a new password.");
    } catch (error) {
      setIsResetAccessVerified(false);
      setErrorMessage(error?.data?.message || error.message || "Invalid or expired verification code.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSubmitReset = async (event) => {
    event.preventDefault();
    setErrorMessage("");
    setSuccessMessage("");

    if (!isResetAccessVerified) {
      return setErrorMessage("Please verify your reset link or verification code first.");
    }

    if (formData.password.length < 6) {
      return setErrorMessage("Password must be at least 6 characters.");
    }

    if (formData.password !== formData.confirmPassword) {
      return setErrorMessage("Passwords do not match.");
    }

    setIsSubmitting(true);

    try {
      const isOtp =
        formData.tokenOrOtp.length <= 6 && /^\d+$/.test(formData.tokenOrOtp);

      if (isOtp) {
        await authService.resetPasswordOtp({
          email: email.toLowerCase().trim(),
          otp: formData.tokenOrOtp.trim(),
          password: formData.password,
          confirmPassword: formData.confirmPassword,
        });
      } else {
        await authService.resetPassword({
          token: formData.tokenOrOtp.trim(),
          password: formData.password,
          confirmPassword: formData.confirmPassword,
        });
      }

      setSuccessMessage("Password reset successful! Redirecting to login...");
      setIsResetSuccessful(true);
      // Rubrik requirement: Required to log in manually after reset
      setTimeout(() => navigate("/login"), 2000);
    } catch (error) {
      setErrorMessage(error?.data?.message || error.message || "Reset failed");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResendCode = async () => {
    setErrorMessage("");
    setSuccessMessage("");
    setIsResending(true);

    try {
      await authService.forgotPassword({ email });
      setFormData((prev) => ({ ...prev, tokenOrOtp: "" }));
      setIsResetAccessVerified(false);
      setStep(1);
      setSuccessMessage("A new verification code has been sent to your email.");
    } catch (error) {
      setErrorMessage(
        error?.data?.message || error.message || "Failed to resend code"
      );
    } finally {
      setIsResending(false);
    }
  };

  if (!email && !urlToken) return null;

  return (
    <div className="auth-page">
      <div className="card shadow-sm auth-card">
        <div className="card-body auth-card-body">
          {isResetSuccessful ? (
            <div className="text-center py-4">
              <div className="display-4 text-success mb-3">{"\u2705"}</div>
              <h2 className="h4 fw-bold">Password Reset</h2>
              <p className="text-muted mb-0">{successMessage}</p>
            </div>
          ) : (
            <>
              <div className="text-center mb-4">
                <h1 className="auth-title">Reset Password</h1>
                <p className="auth-subtitle mb-0">
                  {step === 1
                    ? "Step 1: Verify your identity"
                    : "Step 2: Create new password"}
                </p>
              </div>

              {successMessage && (
                <div className="alert alert-success py-2 small mb-4">
                  {successMessage}
                </div>
              )}

              {errorMessage && (
                <div className="alert alert-danger py-2 small mb-4">
                  {errorMessage}
                </div>
              )}

              {isVerifyingAccess ? (
                <div className="text-center py-4">
                  <div className="spinner-border text-primary mb-3" role="status"></div>
                  <p className="text-muted mb-0">Verifying reset link...</p>
                </div>
              ) : step === 1 ? (
                <form onSubmit={handleVerifyOtp}>
                  <div className="mb-4">
                    <div className="d-flex justify-content-between align-items-center mb-2">
                      <label className="form-label mb-0">Verification Code</label>
                      <button
                        type="button"
                        className="btn btn-link p-0 text-decoration-none small fw-medium"
                        style={{ fontSize: "0.75rem" }}
                        disabled={isResending}
                        onClick={handleResendCode}
                      >
                        {isResending ? "Sending..." : "Resend Email"}
                      </button>
                    </div>
                    <input
                      name="tokenOrOtp"
                      type="text"
                      className="form-control form-control-lg text-center"
                      style={{ letterSpacing: "4px", fontWeight: "bold" }}
                      value={formData.tokenOrOtp}
                      onChange={handleChange}
                      placeholder="------"
                      autoComplete="one-time-code"
                      autoFocus
                    />
                  </div>
                  <button type="submit" className="btn btn-primary w-100" disabled={isSubmitting}>
                    {isSubmitting ? "Verifying..." : "Verify Code"}
                  </button>
                </form>
              ) : isResetAccessVerified ? (
                <form onSubmit={handleSubmitReset}>
                  <div className="mb-3">
                    <label className="form-label">New Password</label>
                    <input
                      name="password"
                      type="password"
                      className="form-control"
                      value={formData.password}
                      onChange={handleChange}
                      placeholder="Enter new password"
                      autoComplete="new-password"
                      autoFocus
                    />
                  </div>
                  <div className="mb-4">
                    <label className="form-label">Confirm Password</label>
                    <input
                      name="confirmPassword"
                      type="password"
                      className="form-control"
                      value={formData.confirmPassword}
                      onChange={handleChange}
                      placeholder="Confirm new password"
                      autoComplete="new-password"
                    />
                  </div>
                  <button
                    type="submit"
                    className="btn btn-primary w-100 mb-3"
                    disabled={isSubmitting}
                  >
                    {isSubmitting ? "Updating..." : "Update Password"}
                  </button>
                </form>
              ) : (
                <div className="text-center py-3">
                  <p className="text-muted mb-0">
                    Verify your reset link or request a new verification email before creating a new password.
                  </p>
                </div>
              )}

              <div className="auth-switch mt-4 text-center">
                <button
                  type="button"
                  className="btn btn-link text-decoration-none auth-link-btn"
                  onClick={() => navigate("/forgot-password")}
                >
                  {"\u2190"} Back to Forgot Password
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

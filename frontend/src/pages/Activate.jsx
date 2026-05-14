import { useEffect, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import authService from "../services/authService";
import useAuth from "../hooks/useAuth";

export default function Activate() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get("token");
  const { fetchMe } = useAuth();
  const activatedTokenRef = useRef("");

  const [state, setState] = useState(() => {
    if (!token) {
      return {
        status: "error",
        message: "No activation token provided.",
      };
    }

    return {
      status: "verifying",
      message: "",
    };
  });

  useEffect(() => {
    if (!token) {
      return;
    }

    if (activatedTokenRef.current === token) {
      return;
    }

    activatedTokenRef.current = token;

    const activateAccount = async () => {
      try {
        const response = await authService.activate({ token });
        setState({
          status: "success",
          message: response?.data?.message || "Account activated successfully!",
        });

        try {
          await fetchMe();
        } catch {
          // Activation can succeed even when the user session is not available.
        }

        setTimeout(() => {
          navigate("/");
        }, 2000);
      } catch (error) {
        setState({
          status: "error",
          message: error?.data?.message || error.message || "Failed to activate account.",
        });
      }
    };

    activateAccount();
  }, [token, fetchMe, navigate]);

  return (
    <div className="auth-page">
      <div className="card shadow-sm auth-card">
        <div className="card-body auth-card-body text-center">
          <h1 className="auth-title mb-4">Activate Account</h1>

          {state.status === "verifying" && (
            <div>
              <div className="spinner-border text-primary mb-3" role="status"></div>
              <p>Verifying account...</p>
            </div>
          )}

          {state.status === "success" && (
            <div className="text-center">
              <div className="display-4 text-success mb-3">{"\u2705"}</div>
              <p className="text-muted mb-4">{state.message}</p>
              <button onClick={() => navigate("/")} className="btn btn-primary w-100">
                Go to Dashboard
              </button>
            </div>
          )}

          {state.status === "error" && (
            <div className="text-center">
              <div className="alert alert-danger py-2 small mb-4">{state.message}</div>
              <button onClick={() => navigate("/")} className="btn btn-outline-secondary w-100">
                Back to Home
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

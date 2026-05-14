import { useCallback, useEffect, useRef, useState } from "react";
import noteService from "../services/noteService";
import { createWebSocket } from "../services/realtimeService";

const MAX_IMAGE_DIMENSION = 1200;
const IMAGE_QUALITY = 0.82;

function compressImage(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();

    reader.onerror = () => reject(new Error("Unable to read image file."));
    reader.onload = () => {
      const img = new Image();

      img.onerror = () => reject(new Error("Unable to load image file."));
      img.onload = () => {
        const scale = Math.min(1, MAX_IMAGE_DIMENSION / Math.max(img.width, img.height));
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(img.width * scale));
        canvas.height = Math.max(1, Math.round(img.height * scale));

        const ctx = canvas.getContext("2d");
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        resolve(canvas.toDataURL("image/jpeg", IMAGE_QUALITY));
      };

      img.src = reader.result;
    };

    reader.readAsDataURL(file);
  });
}

export default function NoteEditor({ note, userId, onClose, onSaveComplete, availableLabels = [] }) {
  const [title, setTitle] = useState(note ? note.title : "");
  const [content, setContent] = useState(note ? note.content : "");
  const [images, setImages] = useState(note?.images ? [...note.images] : []);
  const [labelIds, setLabelIds] = useState(note?.labelIds ? [...note.labelIds] : []);
  const [isSaving, setIsSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState("");
  const [showSettings, setShowSettings] = useState(false);
  const [passwordInput, setPasswordInput] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [shareEmail, setShareEmail] = useState("");
  const [shareRole, setShareRole] = useState("read");
  const [sharedWith, setSharedWith] = useState(note?.sharedWith ? [...note.sharedWith] : []);
  const [editingShareEmail, setEditingShareEmail] = useState("");
  const [editingShareRole, setEditingShareRole] = useState("read");
  const [savingShareEmail, setSavingShareEmail] = useState("");
  const [emailToRemove, setEmailToRemove] = useState(null);
  const [settingsMessage, setSettingsMessage] = useState({ type: "", text: "" });
  const [isOnline, setIsOnline] = useState(typeof navigator === "undefined" ? true : navigator.onLine);

  const saveTimeoutRef = useRef(null);
  const lastSavedNoteRef = useRef(note ?? null);
  const socketRef = useRef(null);
  const hasLocalChangesRef = useRef(() => false);
  const isSavingRef = useRef(isSaving);
  const onCloseRef = useRef(onClose);
  const prevNoteIdRef = useRef(note?.id);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, []);

  useEffect(() => {
    const isJustCreated = !prevNoteIdRef.current && note?.id && lastSavedNoteRef.current?.id === note.id;
    const isSameNote = (note?.id === prevNoteIdRef.current) || isJustCreated;
    prevNoteIdRef.current = note?.id;

    if (isSameNote && note?.id !== undefined) {
      // Just update metadata like permission, don't reset typing state
      return;
    }

    setTitle(note ? note.title : "");
    setContent(note ? note.content : "");
    setImages(note?.images ? [...note.images] : []);
    setLabelIds(note?.labelIds ? [...note.labelIds] : []);
    lastSavedNoteRef.current = note ?? null;
    setSaveMessage("");
    setSettingsMessage({ type: "", text: "" });
    setCurrentPassword("");
    setPasswordInput("");
    setConfirmPassword("");
    setShareEmail("");
    setShareRole("read");
    setSharedWith(note?.sharedWith ? [...note.sharedWith] : []);
    setEditingShareEmail("");
    setEditingShareRole("read");
    setSavingShareEmail("");
  }, [note]);

  const hasLocalChanges = useCallback(() => {
    const saved = lastSavedNoteRef.current;

    if (!saved) {
      return !!title.trim() || !!content.trim() || images.length > 0 || labelIds.length > 0;
    }

    return !(
      saved.title === title &&
      saved.content === content &&
      JSON.stringify(saved.images ?? []) === JSON.stringify(images) &&
      JSON.stringify(saved.labelIds ?? []) === JSON.stringify(labelIds)
    );
  }, [content, images, labelIds, title]);

  useEffect(() => {
    hasLocalChangesRef.current = hasLocalChanges;
    isSavingRef.current = isSaving;
    onCloseRef.current = onClose;
  }, [hasLocalChanges, isSaving, onClose]);

  useEffect(() => {
    const noteId = note?.id;
    const canCollaborate = note?.permission === "edit" && !note?.isLocked;

    if (!noteId || !canCollaborate || !isOnline) {
      if (socketRef.current) {
        socketRef.current.close();
        socketRef.current = null;
      }
      return;
    }

    const socket = createWebSocket();
    socketRef.current = socket;

    socket.onopen = () => {
      console.log("WebSocket Connected");
      socket.send(JSON.stringify({ action: "join", noteId, userId }));
    };

    socket.onmessage = async (event) => {
      try {
        const data = JSON.parse(event.data);
        if (data.action === "note-deleted" && data.noteId === noteId) {
          alert("This note has been deleted by another user.");
          onCloseRef.current();
          return;
        }

        if (data.action === "note-updated" && data.noteId === noteId) {
          if (!hasLocalChangesRef.current() && !isSavingRef.current) {
            const response = await noteService.getNote(noteId);
            const updatedNote = response?.data;

            if (updatedNote) {
              setTitle(updatedNote.title);
              setContent(updatedNote.content);
              setImages(updatedNote.images ?? []);
              setLabelIds(updatedNote.labelIds ?? []);
              lastSavedNoteRef.current = updatedNote;
            }
          }
        }
      } catch (err) {
        console.error("WebSocket message error", err);
      }
    };

    socket.onclose = () => {
      console.log("WebSocket Disconnected");
    };

    return () => {
      if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        socket.close();
      }
    };
  }, [note?.id, note?.isLocked, note?.permission, isOnline, userId]);

  useEffect(() => {
    const saved = lastSavedNoteRef.current;

    if (
      saved &&
      saved.title === title &&
      saved.content === content &&
      JSON.stringify(saved.images ?? []) === JSON.stringify(images) &&
      JSON.stringify(saved.labelIds ?? []) === JSON.stringify(labelIds)
    ) {
      return;
    }

    if (note?.permission === "read") {
      return;
    }

    if (!note && !title.trim() && !content.trim() && images.length === 0) {
      return;
    }

    setIsSaving(true);
    setSaveMessage("Saving...");

    if (saveTimeoutRef.current) clearTimeout(saveTimeoutRef.current);

    saveTimeoutRef.current = setTimeout(async () => {
      try {
        let res = null;
        if (note) {
          const payload = { title, content, labelIds };

          // Only send images if they have changed (Optimization for Rubrik #25)
          const savedImages = lastSavedNoteRef.current?.images ?? [];
          if (JSON.stringify(savedImages) !== JSON.stringify(images)) {
            payload.images = images;
          }

          res = await noteService.updateNote(note.id, payload);
          const savedNote = res?.data ?? null;
          if (savedNote) {
            lastSavedNoteRef.current = savedNote;
            onSaveComplete?.(savedNote);

            // Notify other collaborators via WebSocket
            if (socketRef.current && socketRef.current.readyState === WebSocket.OPEN) {
              socketRef.current.send(JSON.stringify({
                action: 'note-updated',
                noteId: note.id
              }));
            }
          }
        } else {
          const payload = { title, content, images, labelIds };
          res = await noteService.createNote(payload);
          const createdNote = res?.data ?? null;
          if (createdNote) {
            lastSavedNoteRef.current = createdNote;
            onSaveComplete?.(createdNote);
          }
        }

        setSaveMessage(res?.offline ? "Saved." : "Saved.");
      } catch (err) {
        console.error("Auto-save failed", err);
        setSaveMessage("Sync failed. Keep editor open.");
      } finally {
        setIsSaving(false);
      }
    }, 1000);

    return () => clearTimeout(saveTimeoutRef.current);
  }, [title, content, images, labelIds, note, onSaveComplete]);

  const handleImageUpload = async (e) => {
    const files = Array.from(e.target.files);
    const imageFiles = files.filter((file) => file.type.startsWith("image/"));

    if (imageFiles.length === 0) return;

    try {
      const compressedImages = await Promise.all(imageFiles.map(compressImage));
      setImages((prev) => [...prev, ...compressedImages]);
    } catch (err) {
      console.error("Image upload failed", err);
      setSaveMessage("Image upload failed.");
    } finally {
      e.target.value = "";
    }
  };

  const removeImage = (index) => {
    setImages((prev) => prev.filter((_, i) => i !== index));
  };

  const toggleLabel = (id) => {
    setLabelIds((prev) => (prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]));
  };

  const handleUpdatePassword = async () => {
    if (!note) return;

    if (!isOnline) {
      setSettingsMessage({ type: "danger", text: "Note security needs an internet connection." });
      return;
    }

    if (!note.isLocked) {
      if (!passwordInput || !confirmPassword) {
        setSettingsMessage({ type: "danger", text: "Enter the new password twice." });
        return;
      }
    } else if (!currentPassword) {
      setSettingsMessage({ type: "danger", text: "Verify current password first." });
      return;
    }

    if (passwordInput !== confirmPassword) {
      setSettingsMessage({ type: "danger", text: "New passwords do not match." });
      return;
    }

    try {
      await noteService.setNotePassword(note.id, {
        currentPassword,
        newPassword: passwordInput,
        confirmPassword,
      });

      setSettingsMessage({
        type: "success",
        text: note.isLocked
          ? (passwordInput ? "Note password updated successfully." : "Password protection disabled.")
          : "Password protection enabled."
      });

      setCurrentPassword("");
      setPasswordInput("");
      setConfirmPassword("");
      onClose();
    } catch (err) {
      setSettingsMessage({ type: "danger", text: err?.data?.message || err.message || "Security update failed." });
    }
  };

  const handleShare = async () => {
    if (!note || !shareEmail.trim()) {
      setSettingsMessage({ type: "danger", text: "Please enter a recipient email before sharing." });
      return;
    }

    try {
      const emails = shareEmail
        .split(/[;,]/)
        .map((email) => email.trim())
        .filter(Boolean);

      await noteService.shareNote(note.id, shareEmail.trim(), shareRole);

      setSharedWith((prev) => {
        const next = [...prev];
        emails.forEach((email) => {
          const existingIndex = next.findIndex((share) => share.email === email);
          const nextShare = { email, role: shareRole, sharedAt: new Date().toISOString() };

          if (existingIndex >= 0) {
            next[existingIndex] = { ...next[existingIndex], role: shareRole };
          } else {
            next.push(nextShare);
          }
        });

        return next;
      });

      setShareEmail("");
      setSettingsMessage({ type: "success", text: "Note shared successfully." });
    } catch (err) {
      setSettingsMessage({ type: "danger", text: err?.data?.message || err.message || "Unable to share this note." });
    }
  };

  const handleRemoveShare = async () => {
    if (!note || !emailToRemove) return;

    try {
      await noteService.revokeShare(note.id, emailToRemove);
      setSharedWith((prev) => prev.filter((share) => share.email !== emailToRemove));
      if (editingShareEmail === emailToRemove) {
        cancelEditShare();
      }
      setSettingsMessage({ type: "success", text: "Access removed successfully." });
      setEmailToRemove(null);
    } catch (err) {
      setSettingsMessage({ type: "danger", text: err?.data?.message || err.message || "Unable to remove access." });
    }
  };

  const startEditShare = (share) => {
    setEditingShareEmail(share.email);
    setEditingShareRole(share.role === "edit" ? "edit" : "read");
    setSettingsMessage({ type: "", text: "" });
  };

  const cancelEditShare = () => {
    setEditingShareEmail("");
    setEditingShareRole("read");
  };

  const handleUpdateSharePermission = async (email) => {
    if (!note) return;

    try {
      setSavingShareEmail(email);
      await noteService.updateSharePermission(note.id, email, editingShareRole);
      setSharedWith((prev) =>
        prev.map((share) => (share.email === email ? { ...share, role: editingShareRole } : share))
      );
      setEditingShareEmail("");
      setEditingShareRole("read");
      setSettingsMessage({ type: "success", text: "Share permission updated successfully." });
    } catch (err) {
      setSettingsMessage({ type: "danger", text: err?.data?.message || err.message || "Unable to update share permission." });
    } finally {
      setSavingShareEmail("");
    }
  };

  const handleClose = async () => {
    if (saveTimeoutRef.current) clearTimeout(saveTimeoutRef.current);

    if (hasLocalChanges() && !isSaving) {
      setIsSaving(true);
      setSaveMessage("Saving final changes...");
      try {
        let res = null;
        if (note) {
          const payload = { title, content, labelIds };
          
          // Optimization: Only send images if they actually changed
          const savedImages = lastSavedNoteRef.current?.images ?? [];
          if (JSON.stringify(savedImages) !== JSON.stringify(images)) {
            payload.images = images;
          }
          
          res = await noteService.updateNote(note.id, payload);
        } else {
          res = await noteService.createNote({ title, content, images, labelIds });
        }
        if (res?.data) onSaveComplete?.(res.data);
      } catch (err) {
        console.error("Final save failed", err);
      }
    }
    onClose();
  };

  return (
    <div className="note-editor border rounded p-3 bg-white mb-4 shadow-sm position-relative">

      <input
        type="text"
        className="form-control border-0 fw-bold fs-5 mb-2 shadow-none px-0 note-editor-title"
        placeholder="Untitled Note"
        value={title}
        onChange={(e) => setTitle(e.target.value)}
        readOnly={note?.permission === "read"}
      />

      <textarea
        className="form-control border-0 shadow-none px-0 note-editor-textarea"
        placeholder="Take a note..."
        rows="4"
        style={{ resize: "none" }}
        value={content}
        onChange={(e) => setContent(e.target.value)}
        readOnly={note?.permission === "read"}
      />

      {images.length > 0 && (
        <div className="d-flex gap-2 mb-3 overflow-auto pb-2">
          {images.map((img, i) => (
            <div key={i} className="position-relative">
              <img src={img} alt="attachment" style={{ height: "80px", width: "auto", borderRadius: "5px", objectFit: "cover" }} />
              <button
                className="btn btn-sm btn-danger position-absolute top-0 end-0 p-0"
                style={{ width: "20px", height: "20px", borderRadius: "50%", display: note?.permission === "read" ? "none" : "block" }}
                onClick={() => removeImage(i)}
              >
                &times;
              </button>
            </div>
          ))}
        </div>
      )}

      {availableLabels.length > 0 && (
        <div className="mb-3 d-flex flex-wrap gap-2">
          {availableLabels.map((label) => (
            <span
              key={label.id}
              className={`badge rounded-pill cursor-pointer ${labelIds.includes(label.id) ? "bg-primary" : "bg-light text-dark border"}`}
              onClick={() => note?.permission !== "read" && toggleLabel(label.id)}
              style={{ cursor: note?.permission === "read" ? "default" : "pointer" }}
            >
              {label.name}
            </span>
          ))}
        </div>
      )}

      <div className="note-editor-toolbar d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
        <div className="note-editor-actions d-flex align-items-center gap-3">
          <label className={`btn btn-sm btn-outline-secondary mb-0 ${note?.permission === "read" ? "disabled" : ""}`}>
            Images
            <input type="file" multiple accept="image/*" className="d-none" onChange={handleImageUpload} disabled={note?.permission === "read"} />
          </label>

          {note && note.permission === "owner" && (
            <button className={`btn btn-sm ${showSettings ? "btn-secondary" : "btn-outline-secondary"}`} onClick={() => setShowSettings(!showSettings)}>
              Security and Share
            </button>
          )}
        </div>

        <div className="note-editor-status d-flex align-items-center gap-3">
          <span className="text-muted small">{isSaving ? "Saving..." : (saveMessage || "Saved.")}</span>
          <button className="btn btn-sm btn-dark" onClick={handleClose}>
            Close
          </button>
        </div>
      </div>

      {showSettings && note && (
        <div className="note-settings-panel mt-3 p-3 bg-light border rounded">
          {!isOnline && (
            <div className="alert alert-warning py-2 px-3 small">
              Sharing and note security need an internet connection. You can still edit note content offline.
            </div>
          )}

          <h5 className="fw-bold mb-3">Note Security</h5>

          {settingsMessage.text && (
            <div className={`alert alert-${settingsMessage.type} py-2 px-3 small`}>
              {settingsMessage.text}
            </div>
          )}

          {note.isLocked && (
            <div className="mb-3">
              <label className="small text-muted mb-1">Verify Password</label>
              <input
                type="password"
                className="form-control form-control-sm mb-2"
                placeholder="Enter current password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
              />
              <div className="form-text">Verify your current password to modify or remove protection.</div>
            </div>
          )}

          <div className="row g-2 mb-3">
            <div className="col-md-6">
              <label className="small text-muted mb-1">{note.isLocked ? "New Password" : "Secure with Password"}</label>
              <input
                type="password"
                className="form-control form-control-sm"
                placeholder="Create a strong password"
                value={passwordInput}
                onChange={(e) => setPasswordInput(e.target.value)}
              />
              <div className="form-text">
                {note.isLocked ? "Leave both fields blank to remove protection." : "This unique password applies only to this note."}
              </div>
            </div>
            <div className="col-md-6">
              <label className="small text-muted mb-1">Confirm Password</label>
              <input
                type="password"
                className="form-control form-control-sm"
                placeholder="Re-type password to verify"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
              />
            </div>
          </div>

          <button className="btn btn-sm btn-primary w-100 mb-4 py-2 fw-semibold" onClick={handleUpdatePassword} disabled={!isOnline}>
            {note.isLocked ? (passwordInput ? "Update Password" : "Remove Protection") : "Protect this Note"}
          </button>

          <h5 className="fw-bold mb-3">Collaborate</h5>

          <div className="note-editor-share-row d-flex gap-2 align-items-center mb-3">
            <input
              type="email"
              className="form-control form-control-sm"
              placeholder="Enter collaborator email..."
              value={shareEmail}
              onChange={(e) => setShareEmail(e.target.value)}
            />
            <select className="form-select form-select-sm note-editor-role-select" style={{ width: "auto" }} value={shareRole} onChange={(e) => setShareRole(e.target.value)}>
              <option value="read">Viewer</option>
              <option value="edit">Editor</option>
            </select>
            <button className="btn btn-sm btn-success px-3" onClick={handleShare} disabled={!isOnline}>
              Invite
            </button>
          </div>

          {sharedWith.length > 0 && (
            <ul className="list-group list-group-flush border">
              {sharedWith.map((share, index) => (
                <li key={index} className="list-group-item bg-transparent d-flex justify-content-between align-items-center p-2">
                  <div className="d-flex flex-column">
                    <span>
                      <small className="fw-semibold me-2">{share.email}</small>
                      {editingShareEmail === share.email ? (
                        <select
                          className="form-select form-select-sm d-inline-block w-auto"
                          value={editingShareRole}
                          onChange={(e) => setEditingShareRole(e.target.value)}
                          disabled={!isOnline || savingShareEmail === share.email}
                        >
                          <option value="read">Viewer</option>
                          <option value="edit">Editor</option>
                        </select>
                      ) : (
                        <span className="badge bg-secondary">{share.role === "edit" ? "Editor" : "Viewer"}</span>
                      )}
                    </span>
                    {share.sharedAt && <small className="text-muted" style={{ fontSize: "0.7rem" }}>Shared on {new Date(share.sharedAt).toLocaleDateString()}</small>}
                  </div>
                  <div className="d-flex gap-2 align-items-center">
                    {editingShareEmail === share.email ? (
                      <>
                        <button
                          className="btn btn-sm btn-primary py-0"
                          onClick={() => handleUpdateSharePermission(share.email)}
                          disabled={!isOnline || savingShareEmail === share.email}
                        >
                          {savingShareEmail === share.email ? "Saving..." : "Save"}
                        </button>
                        <button className="btn btn-sm btn-outline-secondary py-0" onClick={cancelEditShare} disabled={savingShareEmail === share.email}>
                          Cancel
                        </button>
                      </>
                    ) : (
                      <>
                        <button
                          className="btn btn-sm btn-link text-secondary py-0 px-1"
                          onClick={() => startEditShare(share)}
                          disabled={!isOnline}
                          title="Edit permission"
                          aria-label={`Edit permission for ${share.email}`}
                        >
                          {"\u270F\uFE0F"}
                        </button>
                        <button
                          className="btn btn-sm btn-link text-danger py-0 px-1"
                          onClick={() => setEmailToRemove(share.email)}
                          disabled={!isOnline}
                          title="Remove from note"
                          aria-label={`Remove user from this note ${share.email}`}
                        >
                          {"\uD83D\uDDD1\uFE0F"}
                        </button>
                      </>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {emailToRemove && (
        <div className="modal-backdrop" onClick={() => setEmailToRemove(null)}>
          <div className="modal-content p-4" onClick={e => e.stopPropagation()} style={{ maxWidth: "400px" }}>
            <h5 className="fw-bold">Remove Member?</h5>
            <p className="text-muted small">Are you sure you want to remove <b>{emailToRemove}</b> from this note?</p>
            <div className="d-flex justify-content-end gap-2 mt-3">
              <button className="btn btn-sm btn-light" onClick={() => setEmailToRemove(null)}>Cancel</button>
              <button className="btn btn-sm btn-danger" onClick={handleRemoveShare}>Confirm</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

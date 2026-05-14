import { useState, useEffect, useMemo, useCallback, useRef } from "react";
import useAuth from "../hooks/useAuth";
import useNotes from "../hooks/useNotes";
import noteService from "../services/noteService";
import { syncPendingChanges } from "../services/syncService";
import { createWebSocket } from "../services/realtimeService";
import NoteCard from "../components/NoteCard";
import NoteEditor from "../components/NoteEditor";
import LabelManager from "../components/LabelManager";

export default function Dashboard() {
  const { user, isAuthLoading, refreshAuth } = useAuth();
  const { notes, setNotes, sharedNotes, labels, refreshWorkspace, togglePin, deleteNote } = useNotes(refreshAuth);

  // UI State
  const [activeTab, setActiveTab] = useState("my-notes");
  const [viewMode, setViewMode] = useState("grid");
  const [searchQuery, setSearchQuery] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [selectedNote, setSelectedNote] = useState(null);
  const [isEditorOpen, setIsEditorOpen] = useState(false);
  const [isLabelManagerOpen, setIsLabelManagerOpen] = useState(false);
  const [selectedLabelFilter, setSelectedLabelFilter] = useState(null);
  const [isSidebarMobileOpen, setIsSidebarMobileOpen] = useState(false);
  const [notePendingUnlock, setNotePendingUnlock] = useState(null);
  const [unlockPassword, setUnlockPassword] = useState("");
  const [unlockError, setUnlockError] = useState("");
  const [isUnlocking, setIsUnlocking] = useState(false);
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [syncState, setSyncState] = useState({ status: "idle", pendingCount: 0, errorMessage: "" });

  // Modals/Notices
  const [notePendingDelete, setNotePendingDelete] = useState(null);
  const [deletePassword, setDeletePassword] = useState("");
  const [deleteError, setDeleteError] = useState("");
  const [isDeletingNote, setIsDeletingNote] = useState(false);
  const [shareNotice, setShareNotice] = useState(null);

  const socketRef = useRef(null);
  const knownSharedNoteIdsRef = useRef(new Set());

  // 1. Lifecycle & Connectivity
  useEffect(() => {
    if (!user) return;
    refreshWorkspace();
    const interval = setInterval(refreshWorkspace, 5000);
    return () => clearInterval(interval);
  }, [user, refreshWorkspace]);

  useEffect(() => {
    const handleOnline = async () => {
      setIsOnline(true);
      await syncPendingChanges().catch(() => { });
      refreshWorkspace();
    };
    const handleOffline = () => setIsOnline(false);
    const handleSyncState = (e) => setSyncState(e.detail);
    const handleToggleSidebar = () => setIsSidebarMobileOpen(prev => !prev);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    window.addEventListener("notes-sync-status", handleSyncState);
    window.addEventListener("notes-cache-updated", refreshWorkspace);
    window.addEventListener("toggle-sidebar", handleToggleSidebar);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
      window.removeEventListener("notes-sync-status", handleSyncState);
      window.removeEventListener("notes-cache-updated", refreshWorkspace);
      window.removeEventListener("toggle-sidebar", handleToggleSidebar);
    };
  }, [refreshWorkspace]);

  // 2. Search Debounce
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(searchQuery), 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // 3. Real-time & Notices
  useEffect(() => {
    const socket = createWebSocket();
    socketRef.current = socket;
    socket.onmessage = (e) => {
      try {
        const data = JSON.parse(e.data);
        if (["note-deleted", "note-pinned", "note-updated"].includes(data.action)) refreshWorkspace();
      } catch { }
    };
    return () => socket.readyState <= 1 && socket.close();
  }, [refreshWorkspace]);

  useEffect(() => {
    const currentIds = new Set(sharedNotes.map(n => n.id));
    const newNotes = sharedNotes.filter(n => !knownSharedNoteIdsRef.current.has(n.id));
    knownSharedNoteIdsRef.current = currentIds;

    if (newNotes.length > 0) {
      const first = newNotes[0];
      setShareNotice({ count: newNotes.length, title: first.title || "Untitled", owner: first.ownerDisplayName || first.ownerEmail });
      setTimeout(() => setShareNotice(null), 7000);
    }
  }, [sharedNotes]);

  // 4. Note Processing (Filter & Sort)
  const displayedNotes = useMemo(() => {
    let list = activeTab === "my-notes" ? notes : sharedNotes;
    if (debouncedSearch) {
      const q = debouncedSearch.toLowerCase();
      list = list.filter(n => n.title?.toLowerCase().includes(q) || n.content?.toLowerCase().includes(q));
    }
    if (selectedLabelFilter) {
      list = list.filter(n => n.labelIds?.includes(selectedLabelFilter));
    }
    return [...list].sort((a, b) => {
      if (a.isPinned !== b.isPinned) return a.isPinned ? -1 : 1;
      const timeA = a.isPinned ? a.pinnedAt : a.updatedAt;
      const timeB = b.isPinned ? b.pinnedAt : b.updatedAt;
      return new Date(timeB) - new Date(timeA);
    });
  }, [activeTab, notes, sharedNotes, debouncedSearch, selectedLabelFilter]);

  // 5. Handlers
  const handleOpenEditor = (note = null) => {
    if (note?.isLocked) {
      if (!isOnline) return alert("Locked notes require internet.");
      setNotePendingUnlock(note);
      setUnlockPassword("");
      setUnlockError("");
      return;
    }
    setSelectedNote(note);
    setIsEditorOpen(true);
  };

  const handleConfirmUnlock = async () => {
    if (!notePendingUnlock || !unlockPassword.trim()) return;
    setIsUnlocking(true);
    setUnlockError("");
    try {
      const res = await noteService.verifyNotePassword(notePendingUnlock.id, unlockPassword);
      setSelectedNote(res.data);
      setIsEditorOpen(true);
      setNotePendingUnlock(null);
    } catch (err) {
      setUnlockError("Incorrect password!");
    } finally {
      setIsUnlocking(false);
    }
  };

  const handleTogglePin = async (note) => {
    const original = [...notes];
    setNotes(prev => prev.map(n => n.id === note.id ? { ...n, isPinned: !n.isPinned, pinnedAt: !n.isPinned ? new Date().toISOString() : null } : n));
    try {
      if (note.isLocked && !isOnline) throw new Error("Offline");
      await togglePin(note.id);
      socketRef.current?.send(JSON.stringify({ action: "note-pinned", noteId: note.id }));
    } catch {
      setNotes(original);
      alert("Action failed.");
    }
  };

  const handleDelete = async () => {
    const note = notePendingDelete;
    if (note.isLocked && (!isOnline || !deletePassword.trim())) return setDeleteError("Invalid action for locked note.");

    const original = [...notes];
    setNotes(prev => prev.filter(n => n.id !== note.id));
    setIsDeletingNote(true);
    try {
      await deleteNote(note.id, note.isLocked ? deletePassword : null);
      socketRef.current?.send(JSON.stringify({ action: "note-deleted", noteId: note.id }));
      if (selectedNote?.id === note.id) setIsEditorOpen(false);
      setNotePendingDelete(null);
    } catch {
      setNotes(original);
      setDeleteError("Delete failed.");
    } finally { setIsDeletingNote(false); }
  };

  if (isAuthLoading) return <div className="loading-screen"><div className="spinner-border" /></div>;

  return (
    <div className="app-shell">
      <div className={`sidebar-overlay ${isSidebarMobileOpen ? "visible" : ""}`} onClick={() => setIsSidebarMobileOpen(false)} />

      <aside className={`app-sidebar ${isSidebarMobileOpen ? "mobile-open" : ""}`}>
        <SidebarNavItem icon="📝" label="My Notes" active={activeTab === "my-notes" && !selectedLabelFilter} onClick={() => { setActiveTab("my-notes"); setSelectedLabelFilter(null); setIsSidebarMobileOpen(false); }} />
        <SidebarNavItem icon="👥" label="Shared With Me" active={activeTab === "shared-with-me"} onClick={() => { setActiveTab("shared-with-me"); setSelectedLabelFilter(null); setIsSidebarMobileOpen(false); }} />

        <div className="border-top my-3 mx-4" />
        <div className="px-4 mb-2 small opacity-75 fw-bold">LABELS</div>
        {labels.map(l => (
          <SidebarNavItem key={l.id} icon="🏷️" label={l.name} active={selectedLabelFilter === l.id} onClick={() => { setSelectedLabelFilter(l.id); setActiveTab("my-notes"); setIsSidebarMobileOpen(false); }} />
        ))}
        <SidebarNavItem icon="⚙️" label="Manage Labels" onClick={() => setIsLabelManagerOpen(true)} className="text-primary mt-2" />
      </aside>

      <main className="app-main">
        {shareNotice && <div className="shared-note-notice">
          <div><div className="fw-bold">{shareNotice.count} new shared note(s)</div><small>{shareNotice.owner} shared "{shareNotice.title}"</small></div>
          <button onClick={() => setShareNotice(null)}>&times;</button>
        </div>}

        <div className="offline-status-row">
          <span className={`network-badge ${isOnline ? "network-badge-online" : "network-badge-offline"}`}>{isOnline ? "Online" : "Offline"}</span>
          {syncState.status === "error" && <span className="text-danger small ms-2">{syncState.errorMessage}</span>}
        </div>

        <div className="d-flex align-items-center gap-3 mb-4 flex-nowrap">
          <div className="search-container-ui flex-grow-1">
            <span>🔍</span>
            <input type="text" placeholder="Search notes..." value={searchQuery} onChange={e => setSearchQuery(e.target.value)} />
          </div>
          <div className="btn-group border rounded view-mode-group-ui ms-auto">
            <button className={`btn btn-sm ${viewMode === "grid" ? "btn-primary" : "btn-light"}`} onClick={() => setViewMode("grid")}>Grid</button>
            <button className={`btn btn-sm ${viewMode === "list" ? "btn-primary" : "btn-light"}`} onClick={() => setViewMode("list")}>List</button>
          </div>
        </div>

        {isLabelManagerOpen && <LabelManager labels={labels} onLabelsChanged={refreshWorkspace} onClose={() => setIsLabelManagerOpen(false)} />}

        <UnlockModal note={notePendingUnlock} password={unlockPassword} setPassword={setUnlockPassword} error={unlockError} onCancel={() => setNotePendingUnlock(null)} onConfirm={handleConfirmUnlock} loading={isUnlocking} />
        <DeleteModal note={notePendingDelete} password={deletePassword} setPassword={setDeletePassword} error={deleteError} onCancel={() => setNotePendingDelete(null)} onConfirm={handleDelete} loading={isDeletingNote} />

        {isEditorOpen && (
          <NoteEditor
            note={selectedNote}
            userId={user?.id}
            onClose={() => { setIsEditorOpen(false); refreshWorkspace(); }}
            onSaveComplete={(updatedNote) => setSelectedNote(updatedNote)}
            availableLabels={labels}
          />
        )}

        <div className={viewMode === "grid" ? "note-grid-layout" : "d-flex flex-column gap-3 mb-5"}>
          {displayedNotes.map(note => (
            <div key={note.id}>
              <NoteCard note={note} onClick={handleOpenEditor} onPin={handleTogglePin} onDelete={setNotePendingDelete} availableLabels={labels} />
              {activeTab === "shared-with-me" && <SharedMeta note={note} />}
            </div>
          ))}
          {displayedNotes.length === 0 && (
            <div className="empty-state-ui">
              <div className="empty-state-icon">{debouncedSearch ? "🔍" : "📝"}</div>
              <h3 className="empty-state-title">
                {debouncedSearch ? "No results found" : activeTab === "shared-with-me" ? "No shared notes yet" : "Your notepad is empty"}
              </h3>
              <p className="empty-state-subtitle">
                {debouncedSearch
                  ? `No notes match "${debouncedSearch}". Try a different keyword.`
                  : activeTab === "shared-with-me"
                  ? "Notes shared with you will appear here."
                  : "Click the + button to create your first note."}
              </p>
            </div>
          )}
        </div>

        <button className="fab-btn-ui" onClick={() => handleOpenEditor()}>+</button>
      </main>
    </div>
  );
}

// Internal Helper Components
function SidebarNavItem({ icon, label, active, onClick, className = "" }) {
  return (
    <div className={`sidebar-nav-item ${active ? "active" : ""} ${className}`} onClick={onClick}>
      {icon} <span>{label}</span>
    </div>
  );
}

function SharedMeta({ note }) {
  return (
    <div className="shared-note-meta mt-2 px-2 d-flex justify-content-between">
      <small className="opacity-75">Shared by: <b>{note.ownerDisplayName || note.ownerEmail}</b></small>
      <span className="badge rounded-pill bg-light text-dark border">{note.permission === "edit" ? "Editor" : "Viewer"}</span>
    </div>
  );
}

function UnlockModal({ note, password, setPassword, error, onCancel, onConfirm, loading }) {
  if (!note) return null;
  return (
    <div className="modal-backdrop" onClick={onCancel}>
      <div className="modal-content p-4" onClick={e => e.stopPropagation()} style={{ maxWidth: "400px" }}>
        <h5 className="fw-bold">Unlock Note</h5>
        <p className="opacity-75 small">This note is password protected. Please enter the password to view and edit.</p>
        <input type="password"
          className="form-control mb-2"
          value={password}
          onChange={e => setPassword(e.target.value)}
          onKeyDown={e => e.key === "Enter" && onConfirm()}
          placeholder="Note password"
          autoFocus />
        {error && <div className="text-danger small mb-2">{error}</div>}
        <div className="d-flex justify-content-end gap-2 mt-3">
          <button className="btn btn-sm btn-light" onClick={onCancel} disabled={loading}>Cancel</button>
          <button className="btn btn-sm btn-primary" onClick={onConfirm} disabled={loading}>{loading ? "Unlocking..." : "Unlock"}</button>
        </div>
      </div>
    </div>
  );
}

function DeleteModal({ note, password, setPassword, error, onCancel, onConfirm, loading }) {
  if (!note) return null;
  return (
    <div className="modal-backdrop" onClick={onCancel}>
      <div className="modal-content p-4" onClick={e => e.stopPropagation()}>
        <h5 className="fw-bold">Delete note?</h5>
        <p className="opacity-75 small">This will permanently delete <b>{note.title || "Untitled"}</b>.</p>
        {note.isLocked && <input type="password" className="form-control mb-2" value={password} onChange={e => setPassword(e.target.value)} placeholder="Note password" />}
        {error && <div className="text-danger small mb-2">{error}</div>}
        <div className="d-flex justify-content-end gap-2 mt-3">
          <button className="btn btn-sm btn-light" onClick={onCancel} disabled={loading}>Cancel</button>
          <button className="btn btn-sm btn-danger" onClick={onConfirm} disabled={loading}>{loading ? "Deleting..." : "Delete"}</button>
        </div>
      </div>
    </div>
  );
}

import { request } from "./authService";
import {
  getNoteById,
  getSyncQueue,
  removeNote,
  removeSyncQueueItem,
  replaceTempNote,
  saveLabels,
  saveNote,
  saveNotes,
  saveSharedNotes,
} from "./offlineDb";
import { isBrowserOnline, isOfflineError } from "./offlineUtils";

let isInitialized = false;
let isSyncing = false;

function emitSyncStatus(status, pendingCount = 0, errorMessage = "") {
  window.dispatchEvent(
    new CustomEvent("notes-sync-status", {
      detail: {
        status,
        pendingCount,
        errorMessage,
      },
    })
  );
}

function emitCacheUpdated() {
  window.dispatchEvent(new CustomEvent("notes-cache-updated"));
}

function isRecoverableMissingNoteError(error, item) {
  return error?.status === 404 && ["upsert", "pin", "delete"].includes(item.action);
}

export async function refreshOfflineCaches() {
  if (!isBrowserOnline()) {
    return;
  }

  const [notesResponse, sharedResponse, labelsResponse] = await Promise.all([
    request("/notes", { method: "GET" }),
    request("/shared-notes", { method: "GET" }),
    request("/labels", { method: "GET" }),
  ]);

  await Promise.all([
    saveNotes(notesResponse.data || []),
    saveSharedNotes(sharedResponse.data || []),
    saveLabels(labelsResponse.data || []),
  ]);

  emitCacheUpdated();
}

export async function syncPendingChanges() {
  if (!isBrowserOnline() || isSyncing) {
    return;
  }

  const queue = await getSyncQueue();

  if (queue.length === 0) {
    emitSyncStatus("idle", 0);
    return;
  }

  isSyncing = true;
  emitSyncStatus("syncing", queue.length);

  try {
    for (let index = 0; index < queue.length; index += 1) {
      const item = queue[index];

      try {
        if (item.action === "upsert") {
          if (String(item.noteId).startsWith("local-")) {
            const tempNote = await getNoteById(item.noteId);
            const response = await request("/notes", {
              method: "POST",
              body: JSON.stringify(item.payload),
            });

            const syncedNote = await replaceTempNote(item.noteId, response.data);

            if (tempNote?.isPinned && !syncedNote.isPinned) {
              await request(`/notes/${syncedNote.id}/pin`, {
                method: "POST",
              });
            }
          } else {
            const response = await request(`/notes/${item.noteId}`, {
              method: "PUT",
              body: JSON.stringify(item.payload),
            });

            await saveNote(response.data, { syncStatus: "synced", isLocalOnly: false });
          }
        }

        if (item.action === "pin") {
          // Send specific pin status to server (using the fixed backend API)
          const response = await request(`/notes/${item.noteId}/pin`, {
            method: "POST",
            body: JSON.stringify({ isPinned: item.payload.isPinned })
          });
          
          // Update local cache with the confirmed pinned status from server
          const note = await getNoteById(item.noteId);
          if (note) {
            await saveNote(note, { 
              isPinned: response.data.is_pinned, 
              syncStatus: "synced" 
            });
          }
        }

        if (item.action === "delete") {
          await request(`/notes/${item.noteId}`, {
            method: "DELETE",
            body: JSON.stringify({ password: item.payload?.password ?? null }),
          });
        }
      } catch (error) {
        if (isRecoverableMissingNoteError(error, item)) {
          await removeNote(item.noteId);
          await removeSyncQueueItem(item.id);
          emitSyncStatus("syncing", queue.length - index - 1);
          continue;
        }

        throw error;
      }

      await removeSyncQueueItem(item.id);
      emitSyncStatus("syncing", queue.length - index - 1);
    }

    await refreshOfflineCaches();
    emitSyncStatus("idle", 0);
  } catch (error) {
    if (!isOfflineError(error)) {
      emitSyncStatus("error", queue.length, error?.message || "Unable to sync offline changes.");
    }

    throw error;
  } finally {
    isSyncing = false;
  }
}

export function initializeSync() {
  if (isInitialized || typeof window === "undefined") {
    return;
  }

  isInitialized = true;

  window.addEventListener("online", () => {
    syncPendingChanges().catch(() => {});
  });

  if (isBrowserOnline()) {
    syncPendingChanges().catch(() => {});
  } else {
    emitSyncStatus("idle", 0);
  }
}

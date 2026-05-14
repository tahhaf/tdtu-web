import { request } from "./authService";
import {
  clearQueuedActionsForNote,
  createLocalNoteId,
  enqueueNoteDelete,
  enqueueNotePin,
  enqueueNoteUpsert,
  getAllLabels,
  getAllNotes,
  getAllSharedNotes,
  getNoteById,
  removeNote,
  saveLabels,
  saveNote,
  saveNotes,
  saveSharedNotes,
} from "./offlineDb";
import { isBrowserOnline, isOfflineError } from "./offlineUtils";
import { syncPendingChanges } from "./syncService";

function createOfflineOnlyError(message) {
  const error = new Error(message);
  error.status = 0;
  return error;
}

function shouldUseOfflineQueue(error) {
  return !isBrowserOnline() && isOfflineError(error);
}

function buildOfflineNote(payload, existingNote = null) {
  const now = new Date().toISOString();
  const isCreating = !existingNote;
  const id = existingNote?.id ?? createLocalNoteId();
  const nextPinnedState = payload.isPinned ?? existingNote?.isPinned ?? false;

  return {
    id,
    clientId: existingNote?.clientId ?? payload.clientId ?? id,
    userId: existingNote?.userId ?? 0,
    title: payload.title ?? existingNote?.title ?? "",
    content: payload.content ?? existingNote?.content ?? "",
    noteColor: payload.noteColor ?? existingNote?.noteColor ?? "default",
    isPinned: nextPinnedState,
    pinnedAt: nextPinnedState ? (existingNote?.pinnedAt ?? now) : null,
    isLocked: existingNote?.isLocked ?? false,
    images: payload.images ?? existingNote?.images ?? [],
    labelIds: payload.labelIds ?? existingNote?.labelIds ?? [],
    sharedWith: existingNote?.sharedWith ?? [],
    ownerEmail: existingNote?.ownerEmail ?? null,
    ownerDisplayName: existingNote?.ownerDisplayName ?? null,
    sharedAt: existingNote?.sharedAt ?? null,
    permission: existingNote?.permission ?? "owner",
    createdAt: existingNote?.createdAt ?? now,
    updatedAt: now,
    syncStatus: "pending",
    isLocalOnly: existingNote?.isLocalOnly ?? isCreating,
  };
}

async function loadCachedNoteOrThrow(id) {
  const cachedNote = await getNoteById(id);

  if (!cachedNote) {
    throw createOfflineOnlyError("This note is not available offline yet.");
  }

  return cachedNote;
}

const noteService = {
  async getNotes() {
    try {
      if (isBrowserOnline()) {
        const response = await request("/notes", {
          method: "GET",
        });

        await saveNotes(response.data || []);
        syncPendingChanges().catch(() => {});
        return response;
      }
    } catch (error) {
      if (!isOfflineError(error)) {
        throw error;
      }
    }

    return {
      data: await getAllNotes(),
      offline: true,
    };
  },

  async createNote(payload) {
    try {
      if (isBrowserOnline()) {
        const response = await request("/notes", {
          method: "POST",
          body: JSON.stringify(payload),
        });

        await saveNote(response.data, { syncStatus: "synced", isLocalOnly: false });
        return response;
      }
    } catch (error) {
      if (!shouldUseOfflineQueue(error)) {
        throw error;
      }
    }

    const localNote = buildOfflineNote(payload);
    await saveNote(localNote);
    await enqueueNoteUpsert(localNote);

    return {
      data: localNote,
      offline: true,
    };
  },

  async getNote(id) {
    if (String(id).startsWith("local-")) {
      return {
        data: await loadCachedNoteOrThrow(id),
        offline: true,
      };
    }

    try {
      if (isBrowserOnline()) {
        const response = await request(`/notes/${id}`, {
          method: "GET",
        });

        await saveNote(response.data, { syncStatus: "synced", isLocalOnly: false });
        return response;
      }
    } catch (error) {
      if (!isOfflineError(error)) {
        throw error;
      }
    }

    return {
      data: await loadCachedNoteOrThrow(id),
      offline: true,
    };
  },

  async updateNote(id, payload) {
    if (!String(id).startsWith("local-")) {
      try {
        if (isBrowserOnline()) {
          const response = await request(`/notes/${id}`, {
            method: "PUT",
            body: JSON.stringify(payload),
          });

          await saveNote(response.data, { syncStatus: "synced", isLocalOnly: false });
          return response;
        }
      } catch (error) {
        if (!shouldUseOfflineQueue(error)) {
          throw error;
        }
      }
    }

    const existingNote = await getNoteById(id);

    if (!existingNote) {
      throw createOfflineOnlyError("This note is not available offline yet.");
    }

    const offlineNote = buildOfflineNote(payload, existingNote);
    await saveNote(offlineNote, { syncStatus: "pending", isLocalOnly: String(id).startsWith("local-") });
    await enqueueNoteUpsert(offlineNote);

    return {
      data: offlineNote,
      offline: true,
    };
  },

  async deleteNote(id, password = null) {
    if (String(id).startsWith("local-")) {
      await removeNote(id);
      await clearQueuedActionsForNote(id);

      return {
        data: { message: "Local note deleted." },
        offline: true,
      };
    }

    try {
      if (isBrowserOnline()) {
        const response = await request(`/notes/${id}`, {
          method: "DELETE",
          body: JSON.stringify({ password }),
        });

        await removeNote(id);
        await clearQueuedActionsForNote(id);
        return response;
      }
    } catch (error) {
      if (!shouldUseOfflineQueue(error)) {
        throw error;
      }
    }

    await removeNote(id);
    await enqueueNoteDelete(id, password);

    return {
      data: { message: "Note deleted offline. Sync pending." },
      offline: true,
    };
  },

  async togglePin(id) {
    const existingNote = await loadCachedNoteOrThrow(id);
    const nextPinnedState = !existingNote.isPinned;

    if (!String(id).startsWith("local-")) {
      try {
        if (isBrowserOnline()) {
          const response = await request(`/notes/${id}/pin`, {
            method: "POST",
          });

          await saveNote(
            {
              ...existingNote,
              isPinned: response.data?.is_pinned ?? nextPinnedState,
              pinnedAt: (response.data?.is_pinned ?? nextPinnedState) ? new Date().toISOString() : null,
              updatedAt: new Date().toISOString(),
            },
            { syncStatus: "synced", isLocalOnly: false }
          );

          return response;
        }
      } catch (error) {
        if (!shouldUseOfflineQueue(error)) {
          throw error;
        }
      }
    }

    const offlineNote = buildOfflineNote(
      {
        ...existingNote,
        isPinned: nextPinnedState,
      },
      existingNote
    );

    await saveNote(offlineNote, { syncStatus: "pending", isLocalOnly: String(id).startsWith("local-") });

    if (String(id).startsWith("local-")) {
      await enqueueNoteUpsert(offlineNote);
    } else {
      await enqueueNotePin(id, nextPinnedState);
    }

    return {
      data: { is_pinned: nextPinnedState },
      offline: true,
    };
  },

  async setNotePassword(id, payload) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Note security settings require an internet connection.");
    }

    const response = await request(`/notes/${id}/lock`, {
      method: "POST",
      body: JSON.stringify(payload),
    });

    if (response.data) {
      await saveNote(response.data, { syncStatus: "synced", isLocalOnly: false });
    }

    return response;
  },

  verifyNotePassword(id, password) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Locked notes can only be opened while online.");
    }

    return request(`/notes/${id}/verify-password`, {
      method: "POST",
      body: JSON.stringify({ password }),
    });
  },

  async getSharedNotes() {
    try {
      if (isBrowserOnline()) {
        const response = await request("/shared-notes", {
          method: "GET",
        });

        await saveSharedNotes(response.data || []);
        return response;
      }
    } catch (error) {
      if (!isOfflineError(error)) {
        throw error;
      }
    }

    return {
      data: await getAllSharedNotes(),
      offline: true,
    };
  },

  shareNote(id, email, role) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Sharing notes requires an internet connection.");
    }

    return request(`/notes/${id}/share`, {
      method: "POST",
      body: JSON.stringify({ email, role }),
    });
  },

  revokeShare(id, email) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Sharing settings require an internet connection.");
    }

    return request(`/notes/${id}/revoke-share`, {
      method: "POST",
      body: JSON.stringify({ email }),
    });
  },

  updateSharePermission(id, email, role) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Sharing settings require an internet connection.");
    }

    return request(`/notes/${id}/share`, {
      method: "PATCH",
      body: JSON.stringify({ email, role }),
    });
  },

  async getLabels() {
    try {
      if (isBrowserOnline()) {
        const response = await request("/labels", {
          method: "GET",
        });

        await saveLabels(response.data || []);
        return response;
      }
    } catch (error) {
      if (!isOfflineError(error)) {
        throw error;
      }
    }

    return {
      data: await getAllLabels(),
      offline: true,
    };
  },

  createLabel(name) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Label management is only available while online.");
    }

    return request("/labels", {
      method: "POST",
      body: JSON.stringify({ name }),
    });
  },

  updateLabel(id, name) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Label management is only available while online.");
    }

    return request(`/labels/${id}`, {
      method: "PUT",
      body: JSON.stringify({ name }),
    });
  },

  deleteLabel(id) {
    if (!isBrowserOnline()) {
      throw createOfflineOnlyError("Label management is only available while online.");
    }

    return request(`/labels/${id}`, {
      method: "DELETE",
    });
  },
};

export default noteService;

import { useState } from "react";
import noteService from "../services/noteService";

export default function LabelManager({ onClose, labels, onLabelsChanged }) {
  const [newLabelName, setNewLabelName] = useState("");
  const [editingId, setEditingId] = useState(null);
  const [editingName, setEditingName] = useState("");
  const [labelToDelete, setLabelToDelete] = useState(null);

  const handleAdd = async (e) => {
    e.preventDefault();
    if (!newLabelName.trim()) return;
    try {
      await noteService.createLabel(newLabelName.trim());
      setNewLabelName("");
      await onLabelsChanged?.();
    } catch (err) {
      console.error(err);
    }
  };

  const handleUpdate = async (id) => {
    if (!editingName.trim()) return;
    try {
      await noteService.updateLabel(id, editingName.trim());
      setEditingId(null);
      setEditingName("");
      await onLabelsChanged?.();
    } catch (err) {
      console.error(err);
    }
  };

  const handleDelete = async () => {
    if (!labelToDelete) return;
    try {
      await noteService.deleteLabel(labelToDelete.id);
      setLabelToDelete(null);
      await onLabelsChanged?.();
    } catch (err) {
      console.error(err);
    }
  };

  return (
    <>
      <div className="modal-backdrop d-flex justify-content-center align-items-center" style={{ zIndex: 1050 }}>
        <div className="modal-content bg-white p-4 rounded shadow" style={{ width: "400px" }}>
          <h5 className="mb-3">Manage Labels</h5>

          <form onSubmit={handleAdd} className="d-flex mb-4 gap-2">
            <input
              type="text"
              className="form-control form-control-sm"
              placeholder="New label name..."
              value={newLabelName}
              onChange={(e) => setNewLabelName(e.target.value)}
            />
            <button type="submit" className="btn btn-sm btn-primary">Add</button>
          </form>

          <ul className="list-group mb-4">
            {labels.length === 0 ? (
              <li className="list-group-item text-muted text-center">No labels found</li>
            ) : (
              labels.map(label => (
                <li key={label.id} className="list-group-item d-flex justify-content-between align-items-center p-2">
                  {editingId === label.id ? (
                    <div className="d-flex gap-2 w-100">
                      <input
                        type="text"
                        className="form-control form-control-sm"
                        value={editingName}
                        onChange={(e) => setEditingName(e.target.value)}
                      />
                      <button className="btn btn-sm btn-success py-0" onClick={() => handleUpdate(label.id)}>Save</button>
                      <button className="btn btn-sm btn-outline-secondary py-0" onClick={() => setEditingId(null)}>Cancel</button>
                    </div>
                  ) : (
                    <>
                      <span><span className="badge bg-secondary me-2">tag</span>{label.name}</span>
                      <div>
                        <button
                          className="btn btn-sm btn-link text-primary py-0"
                          onClick={() => { setEditingId(label.id); setEditingName(label.name); }}
                        >Edit</button>
                        <button
                          className="btn btn-sm btn-link text-danger py-0"
                          onClick={() => setLabelToDelete(label)}
                        >Delete</button>
                      </div>
                    </>
                  )}
                </li>
              ))
            )}
          </ul>

          <div className="text-end">
            <button className="btn btn-secondary btn-sm" onClick={onClose}>Close</button>
          </div>
        </div>
      </div>

      {labelToDelete && (
        <div className="modal-backdrop" onClick={() => setLabelToDelete(null)} style={{ zIndex: 1100 }}>
          <div className="modal-content p-4 border-0" onClick={e => e.stopPropagation()} style={{ maxWidth: "400px" }}>
            <h5 className="fw-bold mb-2">Delete label?</h5>
            <p className="opacity-75 small mb-4">
              This will permanently remove the label <b>{labelToDelete.name}</b>. Notes with this label will not be deleted.
            </p>
            <div className="d-flex justify-content-end gap-2">
              <button
                className="btn btn-sm btn-light"
                onClick={() => setLabelToDelete(null)}
              >
                Cancel
              </button>
              <button
                className="btn btn-sm btn-danger px-3"
                onClick={handleDelete}
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

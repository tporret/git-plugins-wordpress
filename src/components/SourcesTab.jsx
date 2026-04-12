import { useState, useEffect, useCallback } from '@wordpress/element';
import { Plus, Trash2, Save, RefreshCw, X } from 'lucide-react';

const API_URL = window.gpwSettings?.restUrl || '/wp-json/gpw/v1';
const NONCE = window.gpwSettings?.nonce || '';
const APP_CONTEXT = window.gpwSettings?.context || {};

function headers(extra = {}) {
  return {
    'Content-Type': 'application/json',
    'X-WP-Nonce': NONCE,
    ...extra,
  };
}

export default function SourcesTab() {
  const [sources, setSources] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState(null);
  const [settingsContext, setSettingsContext] = useState(APP_CONTEXT);
  const [lastError, setLastError] = useState('');

  const showToast = useCallback((message, type = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3000);
  }, []);

  useEffect(() => {
    fetch(`${API_URL}/settings`, { headers: headers() })
      .then((r) => r.json())
      .then((data) => {
        const s = data.sources?.length ? data.sources : [{ target: '', pat: '' }];
        setSources(s);
        setSettingsContext({ ...(data.context || {}), ...APP_CONTEXT });
        setLastError(data.lastError || '');
      })
      .catch(() => showToast('Failed to load settings.', 'error'))
      .finally(() => setLoading(false));
  }, [showToast]);

  const handleChange = (index, field, value) => {
    setSources((prev) =>
      prev.map((s, i) => (i === index ? { ...s, [field]: value } : s))
    );
  };

  const addSource = () => {
    setSources((prev) => [...prev, { target: '', pat: '' }]);
  };

  const removeSource = (index) => {
    setSources((prev) => prev.filter((_, i) => i !== index));
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${API_URL}/settings`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ sources }),
      });
      const data = await res.json();
      if (res.ok) {
        showToast(data.message || 'Settings saved.');
        setLastError('');
      } else {
        showToast(data.message || 'Save failed.', 'error');
      }
    } catch {
      showToast('Network error.', 'error');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20">
        <RefreshCw className="h-6 w-6 animate-spin text-slate-400" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Toast */}
      {toast && (
        <div
          className={`fixed top-4 right-4 z-50 rounded-lg px-4 py-3 text-sm font-medium shadow-lg transition-all ${
            toast.type === 'success'
              ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
              : 'bg-red-50 text-red-700 border border-red-200'
          }`}
        >
          {toast.message}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[1.2fr,0.8fr,1fr]">
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
            Configuration Scope
          </p>
          <h2 className="mt-2 text-lg font-semibold text-slate-900">
            {settingsContext.scope === 'network' ? 'Shared Network Settings' : 'Local Site Settings'}
          </h2>
          <p className="mt-2 text-sm leading-6 text-slate-600">
            {settingsContext.isMultisite
              ? 'Sources and tokens are stored once for the entire network so repository discovery and update tracking stay consistent across sites.'
              : 'Sources and tokens are stored for this site only.'}
          </p>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
            Admin Surface
          </p>
          <div className="mt-2 text-lg font-semibold text-slate-900">
            {settingsContext.isNetworkAdmin ? 'Network Admin' : 'Site Admin'}
          </div>
          <p className="mt-2 text-sm leading-6 text-slate-600">
            {settingsContext.isMultisite
              ? 'Manage shared GitHub credentials from the network admin so all sites use the same source catalogue and cache.'
              : 'Manage GitHub credentials directly from this site admin screen.'}
          </p>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
            Diagnostics
          </p>
          <div className="mt-2 text-sm leading-6 text-slate-600">
            {lastError ? (
              <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-700">
                {lastError}
              </div>
            ) : (
              <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-700">
                No stored GitHub API errors.
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Card */}
      <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-200 px-6 py-4">
          <h2 className="text-base font-semibold text-slate-900">GitHub Sources</h2>
          <p className="mt-1 text-sm text-slate-500">
            Add GitHub users or organizations. Each source can have its own optional PAT for
            private repo access or higher rate limits.
          </p>
        </div>

        <div className="divide-y divide-slate-100">
          {sources.map((source, index) => (
            <div key={index} className="flex items-start gap-4 px-6 py-4">
              {/* Index badge */}
              <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">
                {index + 1}
              </div>

              {/* Target */}
              <div className="flex-1">
                <label className="mb-1 block text-xs font-medium text-slate-500 uppercase tracking-wider">
                  Target Name
                </label>
                <input
                  type="text"
                  placeholder="octocat"
                  value={source.target}
                  onChange={(e) => handleChange(index, 'target', e.target.value)}
                  className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </div>

              {/* PAT */}
              <div className="flex-1">
                <label className="mb-1 block text-xs font-medium text-slate-500 uppercase tracking-wider">
                  Personal Access Token
                </label>
                <div className="relative flex gap-2">
                  <input
                    type="password"
                    placeholder="ghp_…"
                    value={source.pat}
                    onChange={(e) => handleChange(index, 'pat', e.target.value)}
                    disabled={source.pat && source.pat.includes('•')}
                    autoComplete="new-password"
                    className="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900 placeholder-slate-400 transition focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                  />
                  {source.pat && source.pat.includes('•') && (
                    <button
                      type="button"
                      onClick={() => handleChange(index, 'pat', '')}
                      className="flex h-10 w-10 items-center justify-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-500"
                      title="Clear PAT"
                    >
                      <X size={16} />
                    </button>
                  )}
                </div>
                <p className="mt-1 text-xs text-slate-400">
                  Requires read-only access. Classic tokens: <code className="font-mono bg-slate-100 px-1 rounded">public_repo</code> or <code className="font-mono bg-slate-100 px-1 rounded">repo</code>. Fine-grained: <code className="font-mono bg-slate-100 px-1 rounded">contents: Read-only</code>.
                </p>
              </div>

              {/* Remove */}
              <button
                type="button"
                onClick={() => removeSource(index)}
                disabled={sources.length <= 1}
                className="mt-6 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-500 disabled:pointer-events-none disabled:opacity-30"
              >
                <Trash2 size={16} />
              </button>
            </div>
          ))}
        </div>

        {/* Footer actions */}
        <div className="flex items-center justify-between border-t border-slate-200 px-6 py-4">
          <button
            type="button"
            onClick={addSource}
            className="flex items-center gap-2 rounded-lg border border-dashed border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-400 hover:bg-slate-50"
          >
            <Plus size={16} />
            Add Source
          </button>

          <button
            type="button"
            onClick={handleSave}
            disabled={saving}
            className="flex items-center gap-2 rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-60"
          >
            {saving ? <RefreshCw size={16} className="animate-spin" /> : <Save size={16} />}
            {saving ? 'Saving…' : 'Save Settings'}
          </button>
        </div>
      </div>
    </div>
  );
}

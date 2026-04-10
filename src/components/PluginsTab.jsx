import { useState, useEffect, useCallback } from '@wordpress/element';
import { Download, Trash2, RefreshCw, Package, ArrowUpCircle } from 'lucide-react';

const API_URL = window.gpwSettings?.restUrl || '/wp-json/gpw/v1';
const NONCE = window.gpwSettings?.nonce || '';

function headers(extra = {}) {
  return {
    'Content-Type': 'application/json',
    'X-WP-Nonce': NONCE,
    ...extra,
  };
}

function Toggle({ checked, onChange, disabled }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={onChange}
      className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
        checked ? 'bg-emerald-500' : 'bg-slate-200'
      }`}
    >
      <span
        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ${
          checked ? 'translate-x-5' : 'translate-x-0'
        }`}
      />
    </button>
  );
}

export default function PluginsTab() {
  const [plugins, setPlugins] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionLoading, setActionLoading] = useState({});
  const [toast, setToast] = useState(null);

  const showToast = useCallback((message, type = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchPlugins = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/plugins`, { headers: headers() });
      const data = await res.json();
      if (data.message && (!data.plugins || data.plugins.length === 0)) {
        setError(data.message);
      }
      setPlugins(data.plugins || []);
    } catch {
      setError('Failed to load plugins.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchPlugins();
  }, [fetchPlugins]);

  const handleToggle = async (plugin) => {
    const key = `toggle-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const res = await fetch(`${API_URL}/plugins/toggle`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ full_name: plugin.full_name }),
      });
      const data = await res.json();
      if (res.ok) {
        setPlugins((prev) =>
          prev.map((p) =>
            p.full_name === plugin.full_name ? { ...p, is_active: data.is_active } : p
          )
        );
      } else {
        showToast(data.message || 'Toggle failed.', 'error');
      }
    } catch {
      showToast('Network error.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, [key]: false }));
    }
  };

  const handleInstall = async (plugin) => {
    const key = `install-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const res = await fetch(`${API_URL}/plugins/install`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ full_name: plugin.full_name }),
      });
      const data = await res.json();
      if (res.ok) {
        showToast(data.message || 'Installed.');
        await fetchPlugins();
      } else {
        showToast(data.message || 'Install failed.', 'error');
      }
    } catch {
      showToast('Network error.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, [key]: false }));
    }
  };

  const handleForceRefresh = async () => {
    setActionLoading((prev) => ({ ...prev, 'force-refresh': true }));
    try {
      const res = await fetch(`${API_URL}/cache/flush`, {
        method: 'POST',
        headers: headers(),
      });
      const data = await res.json();
      if (res.ok) {
        showToast(data.message || 'Cache cleared.');
        // Refetch plugins immediately
        await fetchPlugins();
      } else {
        showToast(data.message || 'Cache flush failed.', 'error');
      }
    } catch {
      showToast('Network error.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, 'force-refresh': false }));
    }
  };

  const handleUpdate = async (plugin) => {
    const key = `update-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const res = await fetch(`${API_URL}/plugins/update`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ full_name: plugin.full_name, plugin_file: plugin.plugin_file }),
      });
      const data = await res.json();
      if (res.ok) {
        showToast(data.message || 'Updated successfully.');
        await fetchPlugins();
      } else {
        showToast(data.message || 'Update failed.', 'error');
      }
    } catch {
      showToast('Network error.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, [key]: false }));
    }
  };

  const handleUninstall = async (plugin) => {
    if (!window.confirm(`Uninstall "${plugin.name}"? This will delete the plugin files.`)) {
      return;
    }
    const key = `uninstall-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const res = await fetch(`${API_URL}/plugins/uninstall`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({
          full_name: plugin.full_name,
          plugin_file: plugin.plugin_file,
        }),
      });
      const data = await res.json();
      if (res.ok) {
        showToast(data.message || 'Uninstalled.');
        await fetchPlugins();
      } else {
        showToast(data.message || 'Uninstall failed.', 'error');
      }
    } catch {
      showToast('Network error.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, [key]: false }));
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

      {/* Card */}
      <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div className="border-b border-slate-200 px-6 py-4 flex items-center justify-between">
          <div>
            <h2 className="text-base font-semibold text-slate-900">Available Plugins</h2>
            <p className="mt-1 text-sm text-slate-500">
              Plugins discovered from your configured GitHub sources.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={fetchPlugins}
              disabled={loading}
              className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
            >
              <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
              Refresh
            </button>
            <button
              type="button"
              onClick={handleForceRefresh}
              disabled={actionLoading['force-refresh']}
              className="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700 transition hover:bg-amber-100 disabled:opacity-50"
            >
              {actionLoading['force-refresh'] ? (
                <RefreshCw size={14} className="animate-spin" />
              ) : (
                <RefreshCw size={14} />
              )}
              Force Refresh Cache
            </button>
          </div>
        </div>

        {error && (
          <div className="mx-6 mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            {error}
          </div>
        )}

        {plugins.length === 0 && !error ? (
          <div className="flex flex-col items-center justify-center py-16 text-slate-400">
            <Package size={40} strokeWidth={1.5} />
            <p className="mt-3 text-sm">No plugins found. Add a GitHub source first.</p>
          </div>
        ) : (
          <table className="w-full">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs font-medium uppercase tracking-wider text-slate-400">
                <th className="px-6 py-3">Plugin</th>
                <th className="px-6 py-3">Version</th>
                <th className="px-6 py-3">Status</th>
                <th className="px-6 py-3 text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {plugins.map((plugin) => {
                const installKey = `install-${plugin.full_name}`;
                const uninstallKey = `uninstall-${plugin.full_name}`;
                const updateKey = `update-${plugin.full_name}`;
                const toggleKey = `toggle-${plugin.full_name}`;
                const isInstalling = actionLoading[installKey];
                const isUninstalling = actionLoading[uninstallKey];
                const isUpdating = actionLoading[updateKey];
                const isToggling = actionLoading[toggleKey];

                return (
                  <tr key={plugin.full_name} className="hover:bg-slate-50 transition-colors">
                    {/* Plugin name & description */}
                    <td className="px-6 py-4">
                      <div className="font-medium text-slate-900">{plugin.name}</div>
                      {plugin.description && (
                        <div className="mt-0.5 text-sm text-slate-500 line-clamp-1">
                          {plugin.description}
                        </div>
                      )}
                    </td>

                    {/* Version badge */}
                    <td className="px-6 py-4">
                      {plugin.version ? (
                        <div className="flex flex-col gap-1">
                          <span className="inline-block rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700">
                            {plugin.version}
                          </span>
                          {plugin.update_available && plugin.installed_version && (
                            <span className="font-mono text-xs text-amber-600">
                              installed: {plugin.installed_version}
                            </span>
                          )}
                        </div>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </td>

                    {/* Toggle */}
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2">
                        <Toggle
                          checked={plugin.is_active}
                          disabled={isToggling}
                          onChange={() => handleToggle(plugin)}
                        />
                        <span
                          className={`text-xs font-medium ${
                            plugin.is_active ? 'text-emerald-600' : 'text-slate-400'
                          }`}
                        >
                          {plugin.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </div>
                    </td>

                    {/* Action */}
                    <td className="px-6 py-4 text-right">
                      {plugin.is_installed ? (
                        <div className="inline-flex items-center gap-2">
                          {plugin.update_available && (
                            <button
                              type="button"
                              onClick={() => handleUpdate(plugin)}
                              disabled={isUpdating || isUninstalling}
                              className="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-100 disabled:opacity-50"
                            >
                              {isUpdating ? (
                                <RefreshCw size={13} className="animate-spin" />
                              ) : (
                                <ArrowUpCircle size={13} />
                              )}
                              {isUpdating ? 'Updating…' : 'Update'}
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => handleUninstall(plugin)}
                            disabled={isUninstalling || isUpdating}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-50"
                          >
                            {isUninstalling ? (
                              <RefreshCw size={13} className="animate-spin" />
                            ) : (
                              <Trash2 size={13} />
                            )}
                            {isUninstalling ? 'Removing…' : 'Uninstall'}
                          </button>
                        </div>
                      ) : (
                        <button
                          type="button"
                          onClick={() => handleInstall(plugin)}
                          disabled={isInstalling}
                          className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                        >
                          {isInstalling ? (
                            <RefreshCw size={13} className="animate-spin" />
                          ) : (
                            <Download size={13} />
                          )}
                          {isInstalling ? 'Installing…' : 'Install'}
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

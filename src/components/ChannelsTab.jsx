import { useState, useEffect, useCallback } from '@wordpress/element';
import { GitBranch, RefreshCw, Save } from 'lucide-react';
import { getPlugins, getChannels, saveChannels } from '../api';

function normalizeChannel(channel) {
  return channel === 'pre-release' ? 'pre-release' : 'stable';
}

function channelLabel(channel) {
  return channel === 'pre-release' ? 'Pre-release' : 'Stable';
}

export default function ChannelsTab() {
  const [plugins, setPlugins] = useState([]);
  const [defaultChannel, setDefaultChannel] = useState('stable');
  const [pluginChannels, setPluginChannels] = useState({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState(null);

  const showToast = useCallback((message, type = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [pluginsData, channelsData] = await Promise.all([getPlugins(), getChannels()]);

      const pluginRows = (pluginsData.plugins || []).filter(
        (plugin) => plugin.is_installed || plugin.is_tracked
      );

      const nextChannels = {};
      pluginRows.forEach((plugin) => {
        nextChannels[plugin.full_name] = normalizeChannel(plugin.channel);
      });

      setPlugins(pluginRows);
      setPluginChannels(nextChannels);
      setDefaultChannel(normalizeChannel(channelsData.default_channel));
    } catch {
      showToast('Failed to load release channel settings.', 'error');
    } finally {
      setLoading(false);
    }
  }, [showToast]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handlePluginChannelChange = (fullName, channel) => {
    setPluginChannels((prev) => ({
      ...prev,
      [fullName]: normalizeChannel(channel),
    }));
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      const payload = {
        default_channel: defaultChannel,
        plugins: plugins.map((plugin) => ({
          full_name: plugin.full_name,
          channel: normalizeChannel(pluginChannels[plugin.full_name] || defaultChannel),
        })),
      };

      const data = await saveChannels(payload);
      showToast(data.message || 'Release channels saved.');
      await fetchData();
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
      {toast && (
        <div
          className={`fixed top-4 right-4 z-50 rounded-lg px-4 py-3 text-sm font-medium shadow-lg transition-all ${
            toast.type === 'success'
              ? 'border border-emerald-200 bg-emerald-50 text-emerald-700'
              : 'border border-red-200 bg-red-50 text-red-700'
          }`}
        >
          {toast.message}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[0.9fr,1.1fr]">
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
            Default Channel
          </p>
          <h2 className="mt-2 text-lg font-semibold text-slate-900">Release Preference</h2>
          <p className="mt-2 text-sm leading-6 text-slate-600">
            Stable only uses published stable GitHub releases. Pre-release prefers the newest
            non-draft pre-release and falls back to stable when no pre-release exists.
          </p>

          <div className="mt-5 space-y-3">
            {['stable', 'pre-release'].map((channel) => {
              const active = defaultChannel === channel;
              return (
                <label
                  key={channel}
                  className={`flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3 transition ${
                    active
                      ? 'border-slate-900 bg-slate-900 text-white'
                      : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300'
                  }`}
                >
                  <input
                    type="radio"
                    name="gpw-default-channel"
                    value={channel}
                    checked={active}
                    onChange={() => setDefaultChannel(channel)}
                    className="mt-1"
                  />
                  <div>
                    <div className="font-medium">{channelLabel(channel)}</div>
                    <div className={`mt-1 text-sm ${active ? 'text-slate-200' : 'text-slate-500'}`}>
                      {channel === 'stable'
                        ? 'Use only published stable releases.'
                        : 'Prefer preview builds for staging or test environments.'}
                    </div>
                  </div>
                </label>
              );
            })}
          </div>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
            Channel Behavior
          </p>
          <div className="mt-3 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm leading-6 text-slate-600">
            <GitBranch className="mt-0.5 h-4 w-4 flex-none text-slate-500" />
            <div>
              Per-plugin selections override the default channel. Setting a plugin back to the
              default channel removes the explicit override so future default changes still apply.
            </div>
          </div>

          <div className="mt-4 flex justify-end">
            <button
              type="button"
              onClick={handleSave}
              disabled={saving}
              className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
            >
              {saving ? <RefreshCw size={14} className="animate-spin" /> : <Save size={14} />}
              {saving ? 'Saving…' : 'Save Channels'}
            </button>
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-200 px-6 py-4">
          <h2 className="text-base font-semibold text-slate-900">Plugin Channel Overrides</h2>
          <p className="mt-1 text-sm text-slate-500">
            Adjust tracked or installed plugins individually when one repository needs a different
            release cadence than the global default.
          </p>
        </div>

        {plugins.length === 0 ? (
          <div className="px-6 py-10 text-sm text-slate-500">
            No tracked or installed plugins are available for channel overrides yet.
          </div>
        ) : (
          <table className="w-full">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs font-medium uppercase tracking-wider text-slate-400">
                <th className="px-6 py-3">Plugin</th>
                <th className="px-6 py-3">Status</th>
                <th className="px-6 py-3">Channel</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {plugins.map((plugin) => {
                const channel = normalizeChannel(pluginChannels[plugin.full_name] || defaultChannel);
                const usingDefault = channel === defaultChannel;

                return (
                  <tr key={plugin.full_name} className="hover:bg-slate-50 transition-colors">
                    <td className="px-6 py-4">
                      <div className="font-medium text-slate-900">{plugin.name}</div>
                      <div className="mt-0.5 text-sm text-slate-500">{plugin.full_name}</div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex flex-wrap gap-2">
                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                          plugin.is_installed ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'
                        }`}>
                          {plugin.is_installed ? 'Installed' : 'Not installed'}
                        </span>
                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                          plugin.is_tracked ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-600'
                        }`}>
                          {plugin.is_tracked ? 'Tracked' : 'Untracked'}
                        </span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <select
                          value={channel}
                          onChange={(event) => handlePluginChannelChange(plugin.full_name, event.target.value)}
                          className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 transition focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        >
                          <option value="stable">Stable</option>
                          <option value="pre-release">Pre-release</option>
                        </select>
                        <span className={`text-xs font-medium ${usingDefault ? 'text-slate-400' : 'text-slate-600'}`}>
                          {usingDefault ? 'Using default' : 'Override saved on save'}
                        </span>
                      </div>
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
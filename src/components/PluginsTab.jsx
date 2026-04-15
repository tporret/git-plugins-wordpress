import { useState, useEffect, useCallback } from '@wordpress/element';
import { Download, Trash2, RefreshCw, Package, ArrowUpCircle, X, GitBranch, Shield, ShieldCheck } from 'lucide-react';
import {
  getPlugins,
  getPluginSites,
  togglePlugin,
  installPlugin,
  flushCache,
  updatePlugin,
  uninstallPlugin,
} from '../api';

const APP_CONTEXT = window.gpwSettings?.context || {};

function channelLabel(channel) {
  return channel === 'pre-release' ? 'Pre-release' : 'Stable';
}

function formatVerificationDate(value) {
  if (!value) {
    return '';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

function getVerificationMeta(plugin) {
  const verification = plugin.verification || {};
  const verifiedAt = formatVerificationDate(verification.verified_at);

  if (verification.status === 'verified') {
    return {
      label: verification.algorithm === 'sha256' ? 'SHA-256 verified' : 'Verified',
      detail: verifiedAt
        ? `${verification.release_version || plugin.version || 'current release'} checked ${verifiedAt}`
        : verification.release_version || plugin.version || 'Verified package',
      badgeClass: 'bg-emerald-100 text-emerald-700',
      detailClass: 'text-emerald-600',
      Icon: ShieldCheck,
    };
  }

  if (plugin.is_installed) {
    return {
      label: 'Verification unknown',
      detail: 'Install or update with checksum validation to record package verification.',
      badgeClass: 'bg-slate-100 text-slate-600',
      detailClass: 'text-slate-400',
      Icon: Shield,
    };
  }

  return {
    label: 'Pending install',
    detail: 'Verification is recorded after a managed install or update completes.',
    badgeClass: 'bg-amber-50 text-amber-700',
    detailClass: 'text-slate-400',
    Icon: Shield,
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

function SitesModal({ plugin, details, loading, onClose, onLoadMore }) {
  if (!plugin) {
    return null;
  }

  const loadedCount = details?.sites?.length || 0;
  const totalCount = details?.active_site_count || 0;
  const hasMore = details && details.page < details.total_pages;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4 py-8">
      <div className="max-h-[85vh] w-full max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
              Sites with Plugin
            </p>
            <h3 className="mt-2 text-lg font-semibold text-slate-900">{plugin.name}</h3>
            <p className="mt-1 text-sm text-slate-500">
              Review where this plugin is active across the multisite network.
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-10 w-10 items-center justify-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close sites modal"
          >
            <X size={18} />
          </button>
        </div>

        <div className="grid gap-4 border-b border-slate-200 bg-slate-50 px-6 py-4 md:grid-cols-4">
          <div>
            <div className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Installed</div>
            <div className="mt-2 text-sm font-medium text-slate-900">
              {plugin.is_installed ? 'Yes' : 'No'}
            </div>
          </div>
          <div>
            <div className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Tracking</div>
            <div className="mt-2 text-sm font-medium text-slate-900">
              {plugin.is_tracked ? 'Tracked' : 'Not tracked'}
            </div>
          </div>
          <div>
            <div className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Activation</div>
            <div className="mt-2 text-sm font-medium text-slate-900">
              {plugin.is_network_active
                ? 'Network active'
                : totalCount > 0
                  ? 'Site active'
                  : 'Inactive on sites'}
            </div>
          </div>
          <div>
            <div className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Sites</div>
            <div className="mt-2 text-sm font-medium text-slate-900">
              {plugin.is_network_active
                ? `${details?.total_site_count || plugin.total_site_count || 0} total sites`
                : `${totalCount} active`}
            </div>
          </div>
        </div>

        <div className="max-h-[52vh] overflow-y-auto px-6 py-5">
          {loading ? (
            <div className="flex items-center justify-center py-16 text-slate-400">
              <RefreshCw className="h-6 w-6 animate-spin" />
            </div>
          ) : totalCount === 0 ? (
            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600">
              This plugin is installed but not active on any subsite.
            </div>
          ) : (
            <div className="space-y-3">
              {plugin.is_network_active && (
                <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                  This plugin is network activated. Every site listed below inherits that activation.
                </div>
              )}

              {details.sites.map((site) => (
                <div
                  key={site.blog_id}
                  className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white px-4 py-4 md:flex-row md:items-center md:justify-between"
                >
                  <div>
                    <div className="font-medium text-slate-900">{site.name || `Site ${site.blog_id}`}</div>
                    <a
                      href={site.url}
                      target="_blank"
                      rel="noreferrer"
                      className="mt-1 block text-sm text-slate-500 transition hover:text-slate-700"
                    >
                      {site.url}
                    </a>
                  </div>
                  <span
                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                      site.activation_scope === 'network'
                        ? 'bg-sky-100 text-sky-700'
                        : 'bg-emerald-100 text-emerald-700'
                    }`}
                  >
                    {site.activation_scope === 'network' ? 'Network inherited' : 'Site active'}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="flex items-center justify-between border-t border-slate-200 px-6 py-4">
          <div className="text-sm text-slate-500">
            {details
              ? `Showing ${loadedCount} of ${totalCount} site${totalCount === 1 ? '' : 's'}`
              : 'Loading site details'}
          </div>
          <div className="flex items-center gap-2">
            {hasMore && (
              <button
                type="button"
                onClick={onLoadMore}
                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
              >
                Load More
              </button>
            )}
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function PluginsTab() {
  const [plugins, setPlugins] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionLoading, setActionLoading] = useState({});
  const [toast, setToast] = useState(null);
  const [sitesModalPlugin, setSitesModalPlugin] = useState(null);
  const [sitesModalDetails, setSitesModalDetails] = useState(null);
  const [sitesModalLoading, setSitesModalLoading] = useState(false);

  const showToast = useCallback((message, type = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchPlugins = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await getPlugins();
      setPlugins(data.plugins || []);
    } catch (error) {
      setError(error?.message || 'Failed to load plugins.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchPlugins();
  }, [fetchPlugins]);

  const fetchPluginSites = useCallback(async (plugin, page = 1) => {
    if (!plugin?.plugin_file) {
      return;
    }

    setSitesModalLoading(true);
    try {
      const data = await getPluginSites(plugin.plugin_file, page);
      setSitesModalDetails((prev) => {
        if (page === 1 || !prev) {
          return data;
        }
        return {
          ...data,
          sites: [...prev.sites, ...data.sites],
        };
      });
    } catch (error) {
      showToast(error?.message || 'Failed to load site details.', 'error');
    } finally {
      setSitesModalLoading(false);
    }
  }, [showToast]);

  const openSitesModal = async (plugin) => {
    setSitesModalPlugin(plugin);
    setSitesModalDetails(null);
    await fetchPluginSites(plugin, 1);
  };

  const closeSitesModal = () => {
    setSitesModalPlugin(null);
    setSitesModalDetails(null);
    setSitesModalLoading(false);
  };

  const loadMoreSites = async () => {
    if (!sitesModalPlugin || !sitesModalDetails) {
      return;
    }

    await fetchPluginSites(sitesModalPlugin, sitesModalDetails.page + 1);
  };

  const handleToggle = async (plugin) => {
    const key = `toggle-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const data = await togglePlugin(plugin.full_name);
      setPlugins((prev) =>
        prev.map((p) =>
          p.full_name === plugin.full_name
            ? { ...p, is_active: data.is_active, is_tracked: data.is_tracked }
            : p
        )
      );
    } catch (error) {
      showToast(error?.message || 'Toggle failed.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, [key]: false }));
    }
  };

  const handleInstall = async (plugin) => {
    const key = `install-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const data = await installPlugin(plugin.full_name, plugin.channel);
      showToast(data.message || 'Installed.');
      await fetchPlugins();
    } catch (error) {
      showToast(error?.message || 'Install failed.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, [key]: false }));
    }
  };

  const handleForceRefresh = async () => {
    setActionLoading((prev) => ({ ...prev, 'force-refresh': true }));
    try {
      const data = await flushCache();
      showToast(data.message || 'Cache cleared.');
      await fetchPlugins();
    } catch (error) {
      showToast(error?.message || 'Cache flush failed.', 'error');
    } finally {
      setActionLoading((prev) => ({ ...prev, 'force-refresh': false }));
    }
  };

  const handleUpdate = async (plugin) => {
    const key = `update-${plugin.full_name}`;
    setActionLoading((prev) => ({ ...prev, [key]: true }));
    try {
      const data = await updatePlugin(plugin.full_name, plugin.plugin_file, plugin.channel);
      showToast(data.message || 'Updated successfully.');
      await fetchPlugins();
    } catch (error) {
      showToast(error?.message || 'Update failed.', 'error');
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
      const data = await uninstallPlugin(plugin.full_name, plugin.plugin_file);
      showToast(data.message || 'Uninstalled.');
      await fetchPlugins();
    } catch (error) {
      showToast(error?.message || 'Uninstall failed.', 'error');
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

      {APP_CONTEXT.isMultisite && (
        <div className="rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
          <p className="text-sm leading-6 text-slate-600">
            The tracking toggle controls whether a repository is monitored for GitHub releases.
            Activation is shown separately so network-active plugins are not confused with tracked
            repositories.
          </p>
        </div>
      )}

      {APP_CONTEXT.isMultisite && sitesModalPlugin && (
        <SitesModal
          plugin={sitesModalPlugin}
          details={sitesModalDetails}
          loading={sitesModalLoading}
          onClose={closeSitesModal}
          onLoadMore={loadMoreSites}
        />
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
                <th className="px-6 py-3">Versions</th>
                <th className="px-6 py-3">Tracking</th>
                <th className="px-6 py-3">Activation</th>
                {APP_CONTEXT.isMultisite && <th className="px-6 py-3">Sites</th>}
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
                const verificationMeta = getVerificationMeta(plugin);
                const VerificationIcon = verificationMeta.Icon;

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
                      <div className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                        <span
                          className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium ${
                            plugin.channel === 'pre-release'
                              ? 'bg-amber-100 text-amber-700'
                              : 'bg-slate-100 text-slate-700'
                          }`}
                        >
                          <GitBranch size={12} />
                          {channelLabel(plugin.channel)}
                        </span>
                        <span
                          className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium ${verificationMeta.badgeClass}`}
                          title={verificationMeta.detail}
                        >
                          <VerificationIcon size={12} />
                          {verificationMeta.label}
                        </span>
                      </div>
                      <div className={`mt-2 text-[11px] ${verificationMeta.detailClass}`}>
                        {verificationMeta.detail}
                      </div>
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
                    <td className="px-6 py-4 align-middle">
                      <div className="flex items-center gap-3">
                        <Toggle
                          checked={plugin.is_tracked}
                          disabled={isToggling}
                          onChange={() => handleToggle(plugin)}
                        />
                        <div className="flex flex-col justify-center">
                          <span
                            className={`text-xs font-medium ${
                              plugin.is_tracked ? 'text-emerald-600' : 'text-slate-400'
                            }`}
                          >
                            {plugin.is_tracked ? 'Tracked' : 'Not tracked'}
                          </span>
                          <div className="mt-1 text-[11px] text-slate-400">
                            Included in GitHub release checks
                          </div>
                        </div>
                      </div>
                    </td>

                    <td className="px-6 py-4 align-middle">
                      <span
                        className={`inline-flex min-h-[44px] items-center rounded-full px-3 py-1 text-xs font-medium leading-5 ${
                          plugin.is_network_active
                            ? 'bg-sky-100 text-sky-700'
                            : plugin.is_site_active
                              ? 'bg-emerald-100 text-emerald-700'
                              : plugin.is_installed
                                ? 'bg-slate-100 text-slate-600'
                                : 'bg-amber-50 text-amber-700'
                        }`}
                      >
                        {plugin.is_network_active
                          ? 'Network active'
                          : plugin.active_site_count > 0
                            ? 'Site active'
                            : plugin.is_installed
                              ? 'Installed only'
                              : 'Not installed'}
                      </span>
                    </td>

                    {APP_CONTEXT.isMultisite && (
                      <td className="px-6 py-4 align-middle">
                        <div className="flex items-center gap-3">
                          <span
                            className={`inline-flex min-h-[44px] items-center rounded-full px-3 py-1 text-xs font-medium leading-5 ${
                              plugin.is_network_active
                                ? 'bg-sky-100 text-sky-700'
                                : plugin.active_site_count > 0
                                  ? 'bg-emerald-100 text-emerald-700'
                                  : 'bg-slate-100 text-slate-600'
                            }`}
                          >
                            {plugin.sites_summary_label}
                          </span>
                          <button
                            type="button"
                            onClick={() => openSitesModal(plugin)}
                            disabled={!plugin.is_installed}
                            className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                          >
                            View
                          </button>
                        </div>
                      </td>
                    )}

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

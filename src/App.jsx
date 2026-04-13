import { useState } from '@wordpress/element';
import { Github, Settings, GitBranch } from 'lucide-react';
import SourcesTab from './components/SourcesTab';
import PluginsTab from './components/PluginsTab';
import ChannelsTab from './components/ChannelsTab';

const APP_CONTEXT = window.gpwSettings?.context || {};

const TABS = [
  { id: 'sources', label: 'GitHub Sources', icon: Github },
  { id: 'channels', label: 'Release Channels', icon: GitBranch },
  { id: 'plugins', label: 'Available Plugins', icon: Settings },
];

export default function App() {
  const [activeTab, setActiveTab] = useState('sources');
  const isMultisite = Boolean(APP_CONTEXT.isMultisite);
  const scopeLabel = APP_CONTEXT.scope === 'network' ? 'Network' : 'Site';

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      {/* Header */}
      <div className="mb-6 flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-900 text-white">
          <Github size={22} />
        </div>
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Git Plugins Manager</h1>
          <p className="text-sm text-slate-500">{scopeLabel} GitHub source and plugin management</p>
        </div>
      </div>

      {isMultisite && (
        <div className="mb-6 rounded-2xl border border-sky-200 bg-[linear-gradient(135deg,#f0f9ff_0%,#ecfeff_100%)] p-5 shadow-sm">
          <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
            <div>
              <h2 className="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                Network Mode
              </h2>
              <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                GitHub sources, encrypted tokens, tracked repositories, and cache are shared across
                the network. Plugin activation still follows normal WordPress rules, so a plugin
                may be installed here while being inactive, site active, or network active.
              </p>
            </div>
            <div className="rounded-xl border border-white/70 bg-white/70 px-4 py-3 text-sm text-slate-600">
              <div className="font-medium text-slate-900">Admin surface</div>
              <div>{APP_CONTEXT.isNetworkAdmin ? 'Network Admin' : 'Site Admin'}</div>
            </div>
          </div>
        </div>
      )}

      {/* Tab navigation */}
      <div className="mb-6 flex gap-1 rounded-lg bg-slate-100 p-1 w-fit">
        {TABS.map((tab) => {
          const Icon = tab.icon;
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-all ${
                isActive
                  ? 'bg-white text-slate-900 shadow-sm'
                  : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              <Icon size={16} />
              {tab.label}
            </button>
          );
        })}
      </div>

      {/* Tab content */}
      {activeTab === 'sources' && <SourcesTab />}
      {activeTab === 'channels' && <ChannelsTab />}
      {activeTab === 'plugins' && <PluginsTab />}
    </div>
  );
}

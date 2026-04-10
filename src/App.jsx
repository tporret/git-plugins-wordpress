import { useState } from '@wordpress/element';
import { Github, Settings } from 'lucide-react';
import SourcesTab from './components/SourcesTab';
import PluginsTab from './components/PluginsTab';

const TABS = [
  { id: 'sources', label: 'GitHub Sources', icon: Github },
  { id: 'plugins', label: 'Available Plugins', icon: Settings },
];

export default function App() {
  const [activeTab, setActiveTab] = useState('sources');

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      {/* Header */}
      <div className="mb-6 flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-900 text-white">
          <Github size={22} />
        </div>
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Git Plugins Manager</h1>
          <p className="text-sm text-slate-500">Manage GitHub sources and distributed plugins</p>
        </div>
      </div>

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
      {activeTab === 'plugins' && <PluginsTab />}
    </div>
  );
}

const API_URL = window.gpwSettings?.restUrl || '/wp-json/gpw/v1';
const NONCE = window.gpwSettings?.nonce || '';

function buildHeaders(extra = {}) {
  return {
    'Content-Type': 'application/json',
    'X-WP-Nonce': NONCE,
    ...extra,
  };
}

function buildUrl(endpoint) {
  return `${API_URL}${endpoint}`;
}

async function parseJson(response) {
  const text = await response.text();
  if (text === '') {
    return null;
  }

  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function createApiError(response, data) {
  const message = data?.message || `Request failed with status ${response.status}.`;
  const error = new Error(message);
  error.response = response;
  error.data = data;
  return error;
}

export async function apiFetch(endpoint, options = {}) {
  const response = await fetch(buildUrl(endpoint), {
    headers: buildHeaders(options.headers || {}),
    ...options,
  });

  const data = await parseJson(response);
  if (!response.ok) {
    throw createApiError(response, data);
  }

  return data;
}

export function getSettings() {
  return apiFetch('/settings');
}

export function saveSettings(sources) {
  return apiFetch('/settings', {
    method: 'POST',
    body: JSON.stringify({ sources }),
  });
}

export function getChannels() {
  return apiFetch('/channels');
}

export function saveChannels(payload) {
  return apiFetch('/channels', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function getPlugins() {
  return apiFetch('/plugins');
}

export function getPluginSites(pluginFile, page = 1, perPage = 50) {
  const params = new URLSearchParams({
    plugin_file: pluginFile,
    page: String(page),
    per_page: String(perPage),
  });

  return apiFetch(`/plugins/sites?${params.toString()}`);
}

export function togglePlugin(fullName) {
  return apiFetch('/plugins/toggle', {
    method: 'POST',
    body: JSON.stringify({ full_name: fullName }),
  });
}

export function installPlugin(fullName, channel) {
  return apiFetch('/plugins/install', {
    method: 'POST',
    body: JSON.stringify({ full_name: fullName, channel }),
  });
}

export function flushCache() {
  return apiFetch('/cache/flush', {
    method: 'POST',
  });
}

export function updatePlugin(fullName, pluginFile, channel) {
  return apiFetch('/plugins/update', {
    method: 'POST',
    body: JSON.stringify({ full_name: fullName, plugin_file: pluginFile, channel }),
  });
}

export function uninstallPlugin(fullName, pluginFile) {
  return apiFetch('/plugins/uninstall', {
    method: 'POST',
    body: JSON.stringify({ full_name: fullName, plugin_file: pluginFile }),
  });
}

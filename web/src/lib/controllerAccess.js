import api, { v1 } from './api.js'

export async function getLauncherApps() {
  const { data } = await api.get(v1('/auth/controller-apps/launcher'))
  if (!data?.ok) {
    throw new Error(data?.error || 'Failed to load controller apps')
  }
  return data.data
}

export async function getSsoLaunchUrl(appCode) {
  const { data } = await api.get(v1('/auth/sso/launch-url'), {
    params: { app_code: appCode },
  })
  if (!data?.ok) {
    throw new Error(data?.error || 'Failed to get launch URL')
  }
  return data.data
}

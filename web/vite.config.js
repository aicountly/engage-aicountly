import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

/** Base path for GitHub project pages (/<repo>/); user/org pages use '/'. */
function resolveGithubPagesBase() {
  const repo = process.env.GITHUB_REPOSITORY?.split('/')?.[1]
  if (!repo) return '/'
  if (/\.github\.io$/i.test(repo)) return '/'
  return `/${repo}/`
}

export default defineConfig({
  plugins: [react()],
  base:
    process.env.GITHUB_PAGES === 'true' ? resolveGithubPagesBase() : '/',
})

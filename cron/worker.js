/*
 * Cloudflare Worker: at noon Central on Saturday, start the `remind` workflow.
 *
 * See wrangler.toml for why this exists. It does one thing -- ask GitHub to
 * run .github/workflows/remind.yml with dry_run=false -- and throws if GitHub
 * says no, so a failure shows up in the Worker's logs instead of vanishing.
 *
 * dry_run must be sent explicitly. The workflow's manual input defaults to
 * true, so a dispatch that leaves it out prints the list and mails nobody.
 *
 * One-time setup, from this directory:
 *
 *   npx wrangler login
 *   npx wrangler secret put GITHUB_TOKEN
 *   npx wrangler deploy
 *
 * GITHUB_TOKEN is a fine-grained personal access token scoped to this one
 * repository with "Actions: Read and write" and nothing else. It can start
 * workflows; it cannot read or change code, or reach the pool's data.
 */

export const REPO = 'joeguardiola/LoserPoolWebsite';
export const WORKFLOW = 'remind.yml';

export async function dispatchReminder(token, fetchImpl = fetch) {
  const response = await fetchImpl(
    `https://api.github.com/repos/${REPO}/actions/workflows/${WORKFLOW}/dispatches`,
    {
      method: 'POST',
      headers: {
        Accept: 'application/vnd.github+json',
        Authorization: `Bearer ${token}`,
        'User-Agent': 'loser-pool-remind-cron',
        'X-GitHub-Api-Version': '2022-11-28',
      },
      body: JSON.stringify({ ref: 'master', inputs: { dry_run: 'false' } }),
    },
  );

  if (response.status !== 204) {
    throw new Error(`GitHub refused the dispatch: ${response.status} ${await response.text()}`);
  }
}

export default {
  async scheduled(controller, env, ctx) {
    if (!env.GITHUB_TOKEN) {
      throw new Error('GITHUB_TOKEN is not set: npx wrangler secret put GITHUB_TOKEN');
    }
    await dispatchReminder(env.GITHUB_TOKEN);
  },
};

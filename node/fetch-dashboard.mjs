import { UmamiClient } from '@umami/api-client';

async function main() {
	const chunks = [];
	for await (const chunk of process.stdin) {
		chunks.push(chunk);
	}

	const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
	if (!input.baseUrl || !input.token || !input.websiteId || !input.startAt || !input.endAt) {
		throw new Error('Missing required Umami API client configuration.');
	}

	const client = new UmamiClient({
		baseUrl: input.baseUrl,
		token: input.token,
		timeout: 20000,
	});
	const firstStatsRow = value => {
		if (Array.isArray(value)) {
			return value[0] ?? {};
		}
		if (value && typeof value === 'object') {
			if ('pageviews' in value) {
				return value;
			}
			if (value['0'] && typeof value['0'] === 'object') {
				return value['0'];
			}
		}
		return {};
	};
	const range = {
		websiteId: input.websiteId,
		startAt: input.startAt,
		endAt: input.endAt,
	};
	const [rawStats, pageviews, paths, referrers, countries, browsers, operatingSystems, devices] = await Promise.all([
		client.getWebsiteStats(range),
		client.getWebsitePageviews({ ...range, unit: 'day' }),
		client.getWebsiteMetrics({ ...range, type: 'path', limit: 10 }),
		client.getWebsiteMetrics({ ...range, type: 'referrer', limit: 10 }),
		client.getWebsiteMetrics({ ...range, type: 'country', limit: 10 }),
		client.getWebsiteMetrics({ ...range, type: 'browser', limit: 10 }),
		client.getWebsiteMetrics({ ...range, type: 'os', limit: 10 }),
		client.getWebsiteMetrics({ ...range, type: 'device', limit: 10 }),
	]);
	const row = firstStatsRow(rawStats);
	const visits = Number(row.visits ?? 0);
	const stats = {
		pageviews: Number(row.pageviews ?? 0),
		visitors: Number(row.visitors ?? 0),
		visits,
		bounces: Number(row.bounces ?? 0),
		bounceRate: visits ? Math.round((Math.min(Number(row.bounces ?? 0), visits) / visits) * 1000) / 10 : 0,
		averageVisitDurationSeconds: visits ? Math.round(Number(row.totaltime ?? 0) / visits) : 0,
	};

	process.stdout.write(JSON.stringify({
		stats,
		pageviews: pageviews.pageviews ?? [],
		sessions: pageviews.sessions ?? [],
		metrics: {
			paths,
			referrers,
			countries,
			browsers,
			operatingSystems,
			devices,
		},
	}));
}

main().catch((error) => {
	process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
	process.exitCode = 1;
});

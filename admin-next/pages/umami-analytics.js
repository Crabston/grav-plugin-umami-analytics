const tag = window.__GRAV_PAGE_TAG || "grav-umami-analytics--page";

if (!customElements.get(tag)) {
	customElements.define(tag, class extends HTMLElement {
		constructor() {
			super();
			this.attachShadow({ mode: "open" });
			this.days = 7;
		}

		connectedCallback() {
			this.loadDashboard();
		}

		renderMessage(message, isError = false) {
			this.shadowRoot.innerHTML = `
				<style>
					:host { display: block; min-height: 100%; }
					.message {
						display: flex;
						min-height: 12rem;
						flex-direction: column;
						align-items: center;
						justify-content: center;
						gap: 1rem;
						padding: 2rem;
						border: 1px dashed var(--border, #d4d4d8);
						border-radius: 0.75rem;
						color: var(--muted-foreground, #71717a);
						font: 14px/1.6 system-ui, sans-serif;
						text-align: center;
					}
					.error { color: var(--destructive, #dc2626); }
					.spinner {
						width: 2rem;
						height: 2rem;
						border: 3px solid var(--border, #d4d4d8);
						border-top-color: var(--primary, #2563eb);
						border-radius: 50%;
						animation: spin 0.6s linear infinite;
					}
					@keyframes spin { to { transform: rotate(360deg); } }
				</style>
				<div class="message${isError ? " error" : ""}" role="${isError ? "alert" : "status"}">
					${!isError ? '<div class="spinner"></div>' : ''}
					<span></span>
				</div>
			`;
			this.shadowRoot.querySelector(".message span").textContent = message;
		}

		async loadDashboard() {
			this.renderMessage("Loading Umami analytics…");
			try {
				const adminConfig = window.__GRAV_CONFIG__ || {};
				const serverUrl = window.__GRAV_API_SERVER_URL ?? adminConfig.serverUrl ?? "";
				const apiPrefix = window.__GRAV_API_PREFIX || adminConfig.apiPrefix || "/api/v1";
				const endpoint = `${serverUrl.replace(/\/$/, "")}${apiPrefix.replace(/\/$/, "")}/umami-analytics/dashboard?days=${this.days}`;
				const headers = { Accept: "application/json" };
				if (window.__GRAV_API_TOKEN) {
					headers["X-API-Token"] = window.__GRAV_API_TOKEN;
				}
				const response = await fetch(endpoint, { headers, credentials: "same-origin" });
				const result = await response.json();
				if (!response.ok) {
					throw new Error(result.detail || result.message || result.error?.detail || result.error?.message || "Unable to load Umami analytics.");
				}
				if (result.data?.configured === false) {
					this.renderMessage("Configure the Umami API token in Plugins → Umami Analytics to display statistics.");
					return;
				}
				this.renderDashboard(result.data);
			} catch (error) {
				this.renderMessage(error instanceof Error ? error.message : "Unable to load Umami analytics.", true);
			}
		}

		renderDashboard(data) {
			const stats = data.stats ?? {};
			const metrics = data.metrics ?? {};
			this.shadowRoot.innerHTML = `
				<style>
					:host { display: block; color: var(--foreground, #18181b); font: 14px/1.5 system-ui, sans-serif; }
					* { box-sizing: border-box; }
					.toolbar { display: flex; align-items: center; justify-content: flex-end; margin-bottom: 1rem; }
					select {
						border: 1px solid var(--border, #d4d4d8); border-radius: .5rem; padding: .5rem .75rem;
						color: var(--foreground, #18181b); background: var(--background, #fff); font: inherit;
					}
					.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: .75rem; }
					.card, .panel { border: 1px solid var(--border, #e4e4e7); border-radius: .75rem; background: var(--card, #fff); }
					.card { padding: 1rem; }
					.label { color: var(--muted-foreground, #71717a); font-size: .8rem; }
					.value { margin-top: .35rem; font-size: 1.5rem; font-weight: 650; letter-spacing: -.03em; }
					.panel { margin-top: 1rem; padding: 1rem; }
					h2 { margin: 0 0 .75rem; font-size: 1rem; font-weight: 600; }
					.chart { display: flex; height: 190px; align-items: end; gap: 3px; border-bottom: 1px solid var(--border, #e4e4e7); padding: 1rem 0 0; }
					.bar-wrap { display: flex; height: 100%; min-width: 0; flex: 1; align-items: end; }
					.bar { width: 100%; min-height: 2px; border-radius: 3px 3px 0 0; background: var(--primary, #2563eb); }
					.chart-empty { display: flex; height: 190px; align-items: center; justify-content: center; color: var(--muted-foreground, #71717a); }
					.metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: .75rem; margin-top: 1rem; }
					.metric-list { margin: 0; padding: 0; list-style: none; }
					.metric-list li { display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; border-top: 1px solid var(--border, #e4e4e7); }
					.metric-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
					.metric-value { flex: 0 0 auto; color: var(--muted-foreground, #71717a); }
					.empty { color: var(--muted-foreground, #71717a); }
					@media (max-width: 600px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
				</style>
				<div class="toolbar">
					<select aria-label="Statistics date range">
						<option value="7">Last 7 days</option>
						<option value="30">Last 30 days</option>
						<option value="90">Last 90 days</option>
					</select>
				</div>
				<section class="cards">
					<div class="card"><div class="label">Pageviews</div><div class="value" data-stat="pageviews"></div></div>
					<div class="card"><div class="label">Visitors</div><div class="value" data-stat="visitors"></div></div>
					<div class="card"><div class="label">Visits</div><div class="value" data-stat="visits"></div></div>
					<div class="card"><div class="label">Bounce rate</div><div class="value" data-stat="bounces"></div></div>
					<div class="card"><div class="label">Avg. visit duration</div><div class="value" data-stat="totaltime"></div></div>
				</section>
				<section class="panel">
					<h2>Pageviews over time</h2>
					<div class="chart"></div>
				</section>
				<section class="metrics"></section>
			`;

			const formatNumber = value => new Intl.NumberFormat().format(Number(value) || 0);
			const duration = Math.round(Number(stats.averageVisitDurationSeconds) || 0);
			const statValues = {
				pageviews: formatNumber(stats.pageviews),
				visitors: formatNumber(stats.visitors),
				visits: formatNumber(stats.visits),
				bounces: `${Number(stats.bounceRate) || 0}%`,
				totaltime: `${Math.floor(duration / 60)}m ${duration % 60}s`,
			};
			for (const [key, value] of Object.entries(statValues)) {
				this.shadowRoot.querySelector(`[data-stat="${key}"]`).textContent = value;
			}

			const selector = this.shadowRoot.querySelector("select");
			selector.value = String(this.days);
			selector.addEventListener("change", () => {
				this.days = Number(selector.value);
				this.loadDashboard();
			});

			const points = Array.isArray(data.pageviews) ? data.pageviews : [];
			const chart = this.shadowRoot.querySelector(".chart");
			const max = Math.max(1, ...points.map(point => Number(point.y) || 0));
			if (points.length === 0) {
				chart.classList.add("chart-empty");
				chart.textContent = "No pageview data for this period.";
			} else {
				for (const point of points) {
					const wrap = document.createElement("div");
					wrap.className = "bar-wrap";
					const bar = document.createElement("div");
					bar.className = "bar";
					bar.style.height = `${Math.max(1, (Number(point.y) / max) * 100)}%`;
					bar.title = `${new Date(point.x).toLocaleDateString()}: ${formatNumber(point.y)} pageviews`;
					wrap.appendChild(bar);
					chart.appendChild(wrap);
				}
			}

			const labels = [
				["paths", "Top pages"],
				["referrers", "Top referrers"],
				["countries", "Top countries"],
				["browsers", "Browsers"],
				["operatingSystems", "Operating systems"],
				["devices", "Devices"],
			];
			const metricContainer = this.shadowRoot.querySelector(".metrics");
			for (const [key, title] of labels) {
				const panel = document.createElement("section");
				panel.className = "panel";
				const heading = document.createElement("h2");
				heading.textContent = title;
				panel.appendChild(heading);
				const list = document.createElement("ul");
				list.className = "metric-list";
				const rows = Array.isArray(metrics[key]) ? metrics[key] : [];
				if (rows.length === 0) {
					const empty = document.createElement("li");
					empty.className = "empty";
					empty.textContent = "No data";
					list.appendChild(empty);
				}
				for (const row of rows) {
					const item = document.createElement("li");
					const name = document.createElement("span");
					name.className = "metric-name";
					name.textContent = String(row.x ?? "Unknown");
					const value = document.createElement("span");
					value.className = "metric-value";
					value.textContent = formatNumber(row.y);
					item.append(name, value);
					list.appendChild(item);
				}
				panel.appendChild(list);
				metricContainer.appendChild(panel);
			}
		}
	});
}

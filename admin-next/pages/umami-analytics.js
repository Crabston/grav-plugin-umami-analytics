const tag = window.__GRAV_PAGE_TAG || "grav-umami-analytics--page";

if (!customElements.get(tag)) {
	customElements.define(tag, class extends HTMLElement {
		constructor() {
			super();
			this.attachShadow({ mode: "open" });
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
						align-items: center;
						justify-content: center;
						padding: 2rem;
						border: 1px dashed var(--border, #d4d4d8);
						border-radius: 0.75rem;
						color: var(--muted-foreground, #71717a);
						font: 14px/1.6 system-ui, sans-serif;
						text-align: center;
					}
					.error { color: var(--destructive, #dc2626); }
				</style>
				<div class="message${isError ? " error" : ""}" role="${isError ? "alert" : "status"}"></div>
			`;
			this.shadowRoot.querySelector(".message").textContent = message;
		}

		async loadDashboard() {
			this.renderMessage("Loading Umami analytics…");

			try {
				const adminConfig = window.__GRAV_CONFIG__ || {};
				const serverUrl = window.__GRAV_API_SERVER_URL ?? adminConfig.serverUrl ?? "";
				const apiPrefix = window.__GRAV_API_PREFIX || adminConfig.apiPrefix || "/api/v1";
				const endpoint = `${serverUrl.replace(/\/$/, "")}${apiPrefix.replace(/\/$/, "")}/umami-analytics/embed`;
				const headers = { Accept: "application/json" };
				if (window.__GRAV_API_TOKEN) {
					headers["X-API-Token"] = window.__GRAV_API_TOKEN;
				}

				const response = await fetch(endpoint, { headers, credentials: "same-origin" });
				const result = await response.json();
				if (!response.ok) {
					throw new Error(result.detail || result.message || "Unable to load Umami analytics.");
				}

				if (!result.data?.configured || !result.data.share_url) {
					this.renderMessage("Configure the Umami share ID in Plugins → Umami Analytics to display your dashboard.");
					return;
				}

				const shareUrl = new URL(result.data.share_url);
				if (!["http:", "https:"].includes(shareUrl.protocol)) {
					throw new Error("The configured Umami share URL is invalid.");
				}

				this.shadowRoot.innerHTML = `
					<style>
						:host { display: flex; height: 100%; min-height: 42rem; flex-direction: column; gap: 0.75rem; }
						.help {
							margin: 0;
							color: var(--muted-foreground, #71717a);
							font: 13px/1.5 system-ui, sans-serif;
						}
						a { color: var(--primary, #2563eb); }
						.frame-container { min-height: 0; flex: 1; }
						iframe {
							display: block;
							width: 100%;
							height: 100%;
							min-height: 42rem;
							border: 0;
							border-radius: 0.5rem;
							background: var(--background, #fff);
						}
					</style>
					<p class="help">
						If the dashboard is blocked, allow this admin site's origin in Umami's
						<code>ALLOWED_FRAME_URLS</code> setting and rebuild/redeploy Umami.
						Use <code class="origin"></code>.
						<a target="_blank" rel="noopener noreferrer">Open the shared dashboard in a new tab</a>.
					</p>
					<div class="frame-container"></div>
				`;
				this.shadowRoot.querySelector(".origin").textContent = window.location.origin;
				const frame = document.createElement("iframe");
				frame.src = shareUrl.href;
				frame.title = "Umami website analytics";
				frame.referrerPolicy = "no-referrer";
				this.shadowRoot.querySelector("a").href = shareUrl.href;
				this.shadowRoot.querySelector(".frame-container").appendChild(frame);
			} catch (error) {
				this.renderMessage(error instanceof Error ? error.message : "Unable to load Umami analytics.", true);
			}
		}
	});
}

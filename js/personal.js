function t(text) {
	if (typeof window.t === 'function') {
		return window.t('googletwowaysync', text)
	}
	if (window.OC && window.OC.L10N && typeof window.OC.L10N.translate === 'function') {
		return window.OC.L10N.translate('googletwowaysync', text)
	}
	return text
}

function requestToken() {
	if (window.OC && window.OC.requestToken) {
		return window.OC.requestToken
	}
	const head = document.querySelector('head')
	return head ? head.getAttribute('data-requesttoken') || '' : ''
}

function appUrl(path) {
	const route = '/apps/googletwowaysync/' + path
	if (window.OC && typeof window.OC.generateUrl === 'function') {
		return window.OC.generateUrl(route)
	}
	return '/index.php' + route
}

function init() {
	const root = document.getElementById('gtsync')
	if (!root) {
		return
	}
	const state = JSON.parse(root.dataset.state || '{}')
	const account = document.getElementById('gtsync-account')
	const redirect = document.getElementById('gtsync-redirect')
	const calendarList = document.getElementById('gtsync-calendars')
	const contactList = document.getElementById('gtsync-contacts')
	const taskList = document.getElementById('gtsync-tasks')
	const status = document.getElementById('gtsync-status')
	const result = document.getElementById('gtsync-result')
	const buttons = ['connect', 'disconnect', 'save', 'preview', 'sync', 'refresh']
		.map((name) => document.getElementById('gtsync-' + name))

	function setStatus(message, isError) {
		status.textContent = message || ''
		status.classList.toggle('error', Boolean(isError))
		status.classList.toggle('ok', Boolean(message) && !isError)
	}

	function setBusy(busy) {
		buttons.forEach((button) => {
			button.disabled = busy
		})
	}

	function formatTime(stamp) {
		return stamp ? t('Last sync') + ' ' + new Date(stamp * 1000).toLocaleString() : t('Not synced yet')
	}

	function render(next) {
		redirect.textContent = next.redirectUri || ''
		if (next.connected && next.tasksScope) {
			account.textContent = t('Connected. Write access to calendar, contacts, and tasks is granted.')
		} else if (next.connected) {
			account.textContent = t('Connected. To sync tasks, press Connect Google again and allow task access.')
		} else if (next.legacyToken) {
			account.textContent = t('Not connected yet. Google Synchronization\'s read access can only list items and preview.')
		} else {
			account.textContent = t('Not connected yet.')
		}
		const collections = next.collections || []
		fillList(calendarList, collections.filter((col) => col.kind === 'calendar'), t('No calendars. Press Reload list.'), true)
		fillList(contactList, collections.filter((col) => col.kind === 'contacts'), t('No contacts. Press Reload list.'), false)
		const taskEmpty = next.connected && !next.tasksScope
			? t('Task access is missing. Press Connect Google again and allow it.')
			: t('No task lists. Press Reload list.')
		fillList(taskList, collections.filter((col) => col.kind === 'tasks'), taskEmpty, false)
	}

	function colorOf(value) {
		return /^#[0-9A-Fa-f]{3,8}$/.test(value || '') ? value : '#0082c9'
	}

	function fillList(list, collections, emptyText, showMode) {
		list.replaceChildren()
		if (collections.length === 0) {
			const empty = document.createElement('li')
			empty.className = 'empty'
			empty.textContent = emptyText
			list.appendChild(empty)
			return
		}
		collections.forEach((col) => {
			const item = document.createElement('li')
			const label = document.createElement('label')
			label.className = 'row'
			const checkbox = document.createElement('input')
			checkbox.type = 'checkbox'
			checkbox.checked = Boolean(col.enabled)
			checkbox.dataset.id = String(col.id)
			label.appendChild(checkbox)

			const main = document.createElement('span')
			main.className = 'main'
			if (showMode) {
				const swatch = document.createElement('span')
				swatch.className = 'swatch'
				swatch.style.background = colorOf(col.color)
				swatch.setAttribute('aria-hidden', 'true')
				main.appendChild(swatch)
			}
			const name = document.createElement('span')
			name.className = 'name'
			name.textContent = col.name || (showMode ? t('Calendar') : t('Contact'))
			main.appendChild(name)
			const badge = document.createElement('span')
			badge.className = 'badge' + (col.writable ? ' write' : '')
			badge.textContent = col.writable ? t('Two-way') : t('Read only')
			main.appendChild(badge)
			label.appendChild(main)
			item.appendChild(label)

			const bits = [formatTime(col.lastSync)]
			if (col.lastSummary) {
				bits.push(col.lastSummary)
			}
			if (col.legacyJob) {
				bits.push(t('Google Synchronization is enabled, so this is preview only'))
			}
			const meta = document.createElement('p')
			meta.className = 'meta'
			meta.textContent = bits.join(' · ')
			item.appendChild(meta)
			if (col.lastError) {
				const error = document.createElement('p')
				error.className = 'meta error'
				error.textContent = col.lastError
				item.appendChild(error)
			}
			list.appendChild(item)
		})
	}

	function showResult(payload) {
		result.replaceChildren()
		if (!payload || !payload.reports) {
			result.hidden = true
			return
		}
		result.hidden = false
		if (payload.preview) {
			const banner = document.createElement('p')
			banner.className = 'banner'
			banner.textContent = t('This is a preview. Nothing was written.')
			result.appendChild(banner)
		}
		function append(title, reports) {
			if (reports.length === 0) {
				return
			}
			const heading = document.createElement('h4')
			heading.textContent = title
			result.appendChild(heading)
			reports.forEach((report) => {
				const block = document.createElement('div')
				block.className = 'report'
				const summary = document.createElement('p')
				summary.textContent = report.name + ': ' + report.summary
				block.appendChild(summary)
				const lines = report.errors.map((error) => t('Error') + ': ' + error).concat(report.lines)
				if (lines.length > 0) {
					const list = document.createElement('ul')
					lines.forEach((line) => {
						const item = document.createElement('li')
						item.textContent = line
						if (line.startsWith(t('Error') + ':')) {
							item.className = 'error'
						}
						list.appendChild(item)
					})
					block.appendChild(list)
				}
				result.appendChild(block)
			})
		}
		append(t('Calendar'), payload.reports.filter((report) => report.kind === 'calendar'))
		append(t('Contact'), payload.reports.filter((report) => report.kind === 'contacts'))
		append(t('Tasks'), payload.reports.filter((report) => report.kind === 'tasks'))
		append(t('Other'), payload.reports.filter((report) => report.kind !== 'calendar' && report.kind !== 'contacts' && report.kind !== 'tasks'))
	}

	async function call(path, method, body) {
		setBusy(true)
		setStatus('', false)
		try {
			const response = await fetch(appUrl(path), {
				method,
				credentials: 'same-origin',
				headers: {
					requesttoken: requestToken(),
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const payload = await response.json()
			if (!response.ok) {
				throw new Error(payload.error || t('Request failed.'))
			}
			if (payload.collections) {
				render(payload)
			}
			if (payload.state) {
				render(payload.state)
			}
			if (payload.message) {
				setStatus(payload.message, false)
			}
			return payload
		} catch (error) {
			setStatus(error.message || t('Request failed.'), true)
			return null
		} finally {
			setBusy(false)
		}
	}

	function enabledIds() {
		return Array.from(root.querySelectorAll('input[type="checkbox"]'))
			.filter((checkbox) => checkbox.checked)
			.map((checkbox) => Number(checkbox.dataset.id))
	}

	document.getElementById('gtsync-connect').addEventListener('click', async () => {
		const payload = await call('connect', 'POST', {})
		if (payload && payload.url) {
			window.location.href = payload.url
		}
	})
	document.getElementById('gtsync-disconnect').addEventListener('click', () => {
		call('connect', 'DELETE')
	})
	document.getElementById('gtsync-refresh').addEventListener('click', () => {
		call('collections/refresh', 'POST', {})
	})
	document.getElementById('gtsync-save').addEventListener('click', () => {
		call('collections', 'PUT', { enabled: enabledIds() })
	})
	document.getElementById('gtsync-preview').addEventListener('click', async () => {
		result.hidden = false
		result.textContent = t('Previewing…')
		showResult(await call('sync', 'POST', { preview: true }))
	})
	document.getElementById('gtsync-sync').addEventListener('click', async () => {
		result.hidden = false
		result.textContent = t('Syncing…')
		showResult(await call('sync', 'POST', { preview: false }))
	})

	const params = new URLSearchParams(window.location.search)
	if (params.get('gtsyncError')) {
		setStatus(t('Google connection failed: ') + params.get('gtsyncError'), true)
	} else if (params.get('gtsyncConnected')) {
		setStatus(t('Connected the Google account.'), false)
	}
	render(state)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init)
} else {
	init()
}

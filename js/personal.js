function translate(text) {
	const l10n = window.OC && window.OC.L10N
	if (l10n && typeof l10n.translate === 'function') {
		return l10n.translate('googletwowaysync', text)
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
		root.querySelectorAll('button.match').forEach((button) => {
			button.disabled = busy
		})
	}

	function formatTime(stamp) {
		return stamp ? translate('Last sync') + ' ' + new Date(stamp * 1000).toLocaleString() : translate('Not synced yet')
	}

	function render(next) {
		redirect.textContent = next.redirectUri || ''
		if (next.connected && next.tasksScope) {
			account.textContent = translate('Connected. Write access to calendar, contacts, and tasks is granted.')
		} else if (next.connected) {
			account.textContent = translate('Connected. To sync tasks, press Connect Google again and allow task access.')
		} else if (next.legacyToken) {
			account.textContent = translate('Not connected yet. Google Synchronization\'s read access can only list items and preview.')
		} else {
			account.textContent = translate('Not connected yet.')
		}
		const collections = next.collections || []
		fillList(calendarList, collections.filter((col) => col.kind === 'calendar'), translate('No calendars. Press Reload list.'), true)
		fillList(contactList, collections.filter((col) => col.kind === 'contacts'), translate('No contacts. Press Reload list.'), false)
		const taskEmpty = next.connected && !next.tasksScope
			? translate('Task access is missing. Press Connect Google again and allow it.')
			: translate('No task lists. Press Reload list.')
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
			const showColor = col.kind === 'calendar' || col.kind === 'tasks'
			if (showColor) {
				const swatch = document.createElement('span')
				swatch.className = 'swatch'
				swatch.style.background = colorOf(col.color)
				swatch.setAttribute('aria-hidden', 'true')
				main.appendChild(swatch)
			}
			const name = document.createElement('span')
			name.className = 'name'
			name.textContent = col.name || (showMode ? translate('Calendar') : translate('Contact'))
			main.appendChild(name)
			const badge = document.createElement('span')
			badge.className = 'badge' + (col.writable ? ' write' : '')
			badge.textContent = col.writable ? translate('Two-way') : translate('Read only')
			main.appendChild(badge)
			label.appendChild(main)
			item.appendChild(label)

			const bits = [formatTime(col.lastSync)]
			if (col.lastSummary) {
				bits.push(col.lastSummary)
			}
			if (col.legacyJob) {
				bits.push(translate('Google Synchronization is enabled, so this is preview only'))
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
			if (col.kind === 'calendar' || col.kind === 'tasks') {
				const actions = document.createElement('div')
				actions.className = 'meta-actions'
				if (col.nameCustomized) {
					actions.appendChild(matchButton(translate('Match Google name'), () => matchGoogle(col.id, true, false)))
				}
				if (col.colorCustomized) {
					actions.appendChild(matchButton(translate('Match Google color'), () => matchGoogle(col.id, false, true)))
				}
				if (actions.childNodes.length > 0) {
					item.appendChild(actions)
				}
			}
			list.appendChild(item)
		})
	}

	function matchButton(label, onClick) {
		const button = document.createElement('button')
		button.type = 'button'
		button.className = 'match'
		button.textContent = label
		button.addEventListener('click', (event) => {
			event.preventDefault()
			onClick()
		})
		return button
	}

	async function matchGoogle(id, name, color) {
		await call('collections/' + id + '/match-google', 'POST', { name, color })
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
			banner.textContent = translate('This is a preview. Nothing was written.')
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
				const lines = report.errors.map((error) => translate('Error') + ': ' + error).concat(report.lines)
				if (lines.length > 0) {
					const list = document.createElement('ul')
					lines.forEach((line) => {
						const item = document.createElement('li')
						item.textContent = line
						if (line.startsWith(translate('Error') + ':')) {
							item.className = 'error'
						}
						list.appendChild(item)
					})
					block.appendChild(list)
				}
				result.appendChild(block)
			})
		}
		append(translate('Calendar'), payload.reports.filter((report) => report.kind === 'calendar'))
		append(translate('Contact'), payload.reports.filter((report) => report.kind === 'contacts'))
		append(translate('Tasks'), payload.reports.filter((report) => report.kind === 'tasks'))
		append(translate('Other'), payload.reports.filter((report) => report.kind !== 'calendar' && report.kind !== 'contacts' && report.kind !== 'tasks'))
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
				throw new Error(payload.error || translate('Request failed.'))
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
			setStatus(error.message || translate('Request failed.'), true)
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
		result.textContent = translate('Previewing…')
		showResult(await call('sync', 'POST', { preview: true }))
	})
	document.getElementById('gtsync-sync').addEventListener('click', async () => {
		result.hidden = false
		result.textContent = translate('Syncing…')
		showResult(await call('sync', 'POST', { preview: false }))
	})

	const params = new URLSearchParams(window.location.search)
	if (params.get('gtsyncError')) {
		setStatus(translate('Google connection failed: ') + params.get('gtsyncError'), true)
	} else if (params.get('gtsyncConnected')) {
		setStatus(translate('Connected the Google account.'), false)
	}
	render(state)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init)
} else {
	init()
}

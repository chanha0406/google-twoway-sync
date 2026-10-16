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
		return stamp ? '마지막 동기화 ' + new Date(stamp * 1000).toLocaleString() : '아직 동기화하지 않음'
	}

	function render(next) {
		redirect.textContent = next.redirectUri || ''
		if (next.connected && next.tasksScope) {
			account.textContent = '연결되어 있습니다. 캘린더·연락처·할 일 쓰기 권한이 있습니다.'
		} else if (next.connected) {
			account.textContent = '연결되어 있습니다. 할 일을 맞추려면 구글 연결을 다시 눌러 할 일 권한을 허용하세요.'
		} else if (next.legacyToken) {
			account.textContent = '아직 연결하지 않았습니다. Google Synchronization의 읽기 권한으로 목록과 미리보기만 됩니다.'
		} else {
			account.textContent = '아직 연결하지 않았습니다.'
		}
		const collections = next.collections || []
		fillList(calendarList, collections.filter((col) => col.kind === 'calendar'), '캘린더가 없습니다. 목록 다시 가져오기를 누르세요.', true)
		fillList(contactList, collections.filter((col) => col.kind === 'contacts'), '연락처가 없습니다. 목록 다시 가져오기를 누르세요.', false)
		const taskEmpty = next.connected && !next.tasksScope
			? '할 일 권한이 없습니다. 구글 연결을 다시 눌러 허용하세요.'
			: '할 일 목록이 없습니다. 목록 다시 가져오기를 누르세요.'
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
			name.textContent = col.name || (showMode ? '캘린더' : '연락처')
			main.appendChild(name)
			const badge = document.createElement('span')
			badge.className = 'badge' + (col.writable ? ' write' : '')
			badge.textContent = col.writable ? '양방향' : '읽기 전용'
			main.appendChild(badge)
			label.appendChild(main)
			item.appendChild(label)

			const bits = [formatTime(col.lastSync)]
			if (col.lastSummary) {
				bits.push(col.lastSummary)
			}
			if (col.legacyJob) {
				bits.push('Google Synchronization이 켜져 있어 미리보기만 됩니다')
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
			banner.textContent = '미리보기입니다. 아무것도 쓰지 않았습니다.'
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
				const lines = report.errors.map((error) => '오류: ' + error).concat(report.lines)
				if (lines.length > 0) {
					const list = document.createElement('ul')
					lines.forEach((line) => {
						const item = document.createElement('li')
						item.textContent = line
						if (line.startsWith('오류:')) {
							item.className = 'error'
						}
						list.appendChild(item)
					})
					block.appendChild(list)
				}
				result.appendChild(block)
			})
		}
		append('캘린더', payload.reports.filter((report) => report.kind === 'calendar'))
		append('연락처', payload.reports.filter((report) => report.kind === 'contacts'))
		append('할 일', payload.reports.filter((report) => report.kind === 'tasks'))
		append('기타', payload.reports.filter((report) => report.kind !== 'calendar' && report.kind !== 'contacts' && report.kind !== 'tasks'))
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
				throw new Error(payload.error || '요청에 실패했습니다.')
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
			setStatus(error.message || '요청에 실패했습니다.', true)
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
		result.textContent = '미리보기 중…'
		showResult(await call('sync', 'POST', { preview: true }))
	})
	document.getElementById('gtsync-sync').addEventListener('click', async () => {
		result.hidden = false
		result.textContent = '동기화 중…'
		showResult(await call('sync', 'POST', { preview: false }))
	})

	const params = new URLSearchParams(window.location.search)
	if (params.get('gtsyncError')) {
		setStatus('구글 연결 실패: ' + params.get('gtsyncError'), true)
	} else if (params.get('gtsyncConnected')) {
		setStatus('구글 계정을 연결했습니다.', false)
	}
	render(state)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init)
} else {
	init()
}

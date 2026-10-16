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
	const root = document.getElementById('gtsync-admin')
	if (!root) {
		return
	}
	const state = JSON.parse(root.dataset.state || '{}')
	const clientId = document.getElementById('gtsync-client-id')
	const clientSecret = document.getElementById('gtsync-client-secret')
	const redirect = document.getElementById('gtsync-admin-redirect')
	const source = document.getElementById('gtsync-admin-source')
	const status = document.getElementById('gtsync-admin-status')
	const saveButton = document.getElementById('gtsync-admin-save')

	function setStatus(message, isError) {
		status.textContent = message || ''
		status.classList.toggle('error', Boolean(isError))
		status.classList.toggle('ok', Boolean(message) && !isError)
	}

	function render(next) {
		redirect.textContent = next.redirectUri || ''
		clientId.value = next.clientId || ''
		clientSecret.value = ''
		if (next.secretSet) {
			clientSecret.placeholder = translate('Saved. Enter a new secret only to change it')
		} else {
			clientSecret.placeholder = ''
		}
		if (next.usingOwn) {
			source.textContent = translate('This app is using its own OAuth client.')
		} else if (next.fallback && next.fallbackSharedWithSocialLogin) {
			source.textContent = translate('These fields are empty, so this app uses the Google Synchronization client. That client is the same one Social Login uses for Google sign-in. Create a new client and paste it here.')
		} else if (next.fallback) {
			source.textContent = translate('These fields are empty, so this app uses the Google Synchronization client.')
		} else {
			source.textContent = translate('No OAuth client is configured yet.')
		}
	}

	async function save() {
		saveButton.disabled = true
		setStatus('', false)
		try {
			const response = await fetch(appUrl('admin-config'), {
				method: 'PUT',
				credentials: 'same-origin',
				headers: {
					requesttoken: requestToken(),
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				body: JSON.stringify({
					clientId: clientId.value,
					clientSecret: clientSecret.value,
				}),
			})
			const payload = await response.json()
			if (!response.ok) {
				throw new Error(payload.error || translate('Request failed.'))
			}
			render(payload)
			setStatus(payload.message || translate('Saved.'), false)
		} catch (error) {
			setStatus(error.message || translate('Request failed.'), true)
		} finally {
			saveButton.disabled = false
		}
	}

	saveButton.addEventListener('click', () => {
		const confirmation = window.OC && window.OC.PasswordConfirmation
		if (confirmation && typeof confirmation.requirePasswordConfirmation === 'function') {
			confirmation.requirePasswordConfirmation(save, null, () => {
				setStatus(translate('Password confirmation was cancelled.'), true)
			})
			return
		}
		save()
	})

	render(state)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init)
} else {
	init()
}

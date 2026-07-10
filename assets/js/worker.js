/**
 * WavelogWorker — generic browser client for the Wavelog Worker WebSocket.
 *
 * Loaded globally (footer.php) whenever the Worker integration is enabled; PHP
 * sets `window.WavelogWorker = { url }` with the public client URL just before
 * this file, which then augments that object with the client API.
 *
 * Any Wavelog area can open an authenticated, auto-reconnecting subscription to
 * a worker topic without re-implementing the WS/auth/reconnect handshake:
 *
 *     var conn = WavelogWorker.subscribe({
 *         topic: 'worker.status',
 *         token: '<hmac minted server-side for this topic>',
 *         onOpen:    function ()      {  },   // authenticated (auth_ok)
 *         onMessage: function (frame) {  },   // every non-control frame
 *         onClose:   function ()      {  },   // socket dropped (will reconnect)
 *         onFailed:  function ()      {  },   // gave up / server rejected us
 *     });
 *     conn.send({ type: 'status' });
 *     conn.close();
 *
 * The connection layer is deliberately generic: no status polling, no AJAX
 * fallback — consumers build that on top via the callbacks.
 */
(function () {
	'use strict';

	var W = window.WavelogWorker = window.WavelogWorker || {};

	/** True when the Worker integration exposed a client URL. */
	W.isAvailable = function () { return !!W.url; };

	/**
	 * Open an authenticated, auto-reconnecting subscription to a topic.
	 * @param {object} opts topic, token, onOpen, onMessage(frame), onClose,
	 *   onReconnecting(attempt), onFailed, and optional connectTimeoutMs /
	 *   retryDelayMs / maxRetries overrides.
	 * @returns {{send: function, close: function, isConnected: function}}
	 */
	W.subscribe = function (opts) {
		opts = opts || {};
		var baseUrl          = (W.url || '').replace(/\/$/, '');
		var topic            = opts.topic;
		var token            = opts.token;
		var connectTimeoutMs = opts.connectTimeoutMs || 1500;
		var retryDelayMs     = opts.retryDelayMs || 1000;
		var maxRetries       = (opts.maxRetries != null) ? opts.maxRetries : 4;

		var ws = null;
		var ready = false;          // auth_ok received
		var settled = false;        // this attempt already resolved to a failure
		var wantConnected = true;   // false once we give up or the caller closes
		var attempt = 0;            // consecutive reconnect attempts (reset on auth_ok)
		var connectTimer = null;
		var retryTimer = null;

		function hook(fn) {
			if (typeof fn !== 'function') { return; }
			try { fn.apply(null, Array.prototype.slice.call(arguments, 1)); } catch (e) { /* never break the state machine */ }
		}

		function clearConnectTimer() { if (connectTimer) { clearTimeout(connectTimer); connectTimer = null; } }

		function teardown() {
			clearConnectTimer();
			clearTimeout(retryTimer);
			if (ws) { try { ws.close(); } catch (e) {} ws = null; }
		}

		// Terminal stop: no more reconnects. Used by close() and on server rejection.
		function giveUp(callFailed) {
			wantConnected = false;
			settled = true;
			teardown();
			if (callFailed) { hook(opts.onFailed); }
		}

		function open() {
			var sock;
			try {
				sock = new WebSocket(baseUrl + '/ws?topic=' + encodeURIComponent(topic));
			} catch (e) { fail(); return; }
			ws = sock;
			ready = false;
			settled = false;
			function isCurrent() { return sock === ws; }

			clearConnectTimer();
			connectTimer = setTimeout(function () {
				if (isCurrent() && !ready) { fail(); }   // hung connect → retry
			}, connectTimeoutMs);

			sock.addEventListener('open', function () {
				if (!isCurrent()) { return; }
				sock.send(JSON.stringify({ type: 'auth', token: token }));
			});

			sock.addEventListener('message', function (event) {
				if (!isCurrent()) { return; }
				var msg;
				try { msg = JSON.parse(event.data); } catch (e) { return; }
				if (msg.type === 'auth_ok') {
					ready = true;
					settled = false;
					attempt = 0;
					clearConnectTimer();
					hook(opts.onOpen);
				} else if (msg.type === 'error') {
					// Server actively rejected us (bad/expired token, unknown topic) —
					// retrying won't help, so stop instead of hammering.
					giveUp(true);
				} else {
					hook(opts.onMessage, msg);
				}
			});

			sock.addEventListener('close', function () {
				if (!isCurrent()) { return; }
				ready = false;
				hook(opts.onClose);
				fail();
			});

			sock.addEventListener('error', function () { /* a close event always follows */ });
		}

		// Resolve a failed/closed attempt, deduplicated so the connect-timeout and
		// the close event can never both count as a failure for the same socket.
		function fail() {
			if (settled) { return; }
			settled = true;
			clearConnectTimer();
			ready = false;
			if (ws) { try { ws.close(); } catch (e) {} ws = null; }
			if (!wantConnected) { return; }
			scheduleRetry();
		}

		function scheduleRetry() {
			if (attempt >= maxRetries) { giveUp(true); return; }
			attempt++;
			hook(opts.onReconnecting, attempt);
			clearTimeout(retryTimer);
			retryTimer = setTimeout(function () { if (wantConnected) { open(); } }, retryDelayMs);
		}

		open();

		return {
			send: function (frame) {
				if (ws && ready && ws.readyState === 1) {
					ws.send(typeof frame === 'string' ? frame : JSON.stringify(frame));
					return true;
				}
				return false;
			},
			close: function () { giveUp(false); },
			isConnected: function () { return ready; }
		};
	};
})();

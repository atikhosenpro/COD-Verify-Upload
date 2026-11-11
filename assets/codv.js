jQuery(function ($) {
	var cfg = window.codvData;
	if (!cfg) { return; }
	var $form = $('form.checkout');

	var ICON = {
		gallery: '<i class="fa-regular fa-images" aria-hidden="true"></i>',
		flip: '<i class="fa-solid fa-camera-rotate" aria-hidden="true"></i>',
		close: '<i class="fa-solid fa-xmark" aria-hidden="true"></i>',
		camera: '<i class="fa-solid fa-camera" aria-hidden="true"></i>'
	};

	function isCod() {
		var v = $form.find('input[name="payment_method"]:checked').val();
		return $.inArray(v, cfg.gateways) !== -1;
	}

	// Keep the panel just above the Place Order button, and survive WooCommerce's fragment refresh.
	function place() {
		var $p = $('#codv-panel');
		if (!$p.length) { return; }
		var $po = $form.find('.place-order').first();
		if (!$po.length) { $po = $('#place_order').parent(); }
		if (isCod() && $po.length) {
			if (!$po.children('#codv-panel').length) { $po.prepend($p); }
			$p.show();
		} else {
			$p.hide();
			$('#codv-holder').append($p);
		}
	}

	$(document.body).on('update_checkout', function () {
		$('#codv-holder').append($('#codv-panel'));
	});
	$(document.body).on('updated_checkout payment_method_selected', place);
	$form.on('change', 'input[name="payment_method"]', place);
	place();

	/* ---------- Mode tabs (upload / NID info) ---------- */
	function currentMode() { return $('#codv-panel .codv-mode').val(); }

	$(document).on('click', '#codv-panel .codv-tab', function () {
		var m = $(this).data('mode');
		$('#codv-panel .codv-tab').removeClass('is-active');
		$(this).addClass('is-active');
		$('#codv-panel .codv-mode').val(m);
		$('#codv-panel .codv-pane').attr('hidden', true);
		$('#codv-panel .codv-pane[data-pane="' + m + '"]').removeAttr('hidden');
		$('#codv-panel .codv-error').text('');
	});

	/* ---------- Helpers ---------- */
	function setMsg($s, t) { $s.find('.codv-msg').text(t || ''); }

	function toEn(v) {
		return String(v || '').replace(/[\u09E6-\u09EF]/g, function (d) { return String(d.charCodeAt(0) - 0x09E6); });
	}

	function compress(file) {
		return new Promise(function (resolve) {
			if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
			var img = new Image();
			var url = URL.createObjectURL(file);
			img.onload = function () {
				var max = 1600, w = img.width, h = img.height;
				var r = Math.min(1, max / Math.max(w, h));
				var c = document.createElement('canvas');
				c.width = Math.round(w * r);
				c.height = Math.round(h * r);
				c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
				c.toBlob(function (b) { URL.revokeObjectURL(url); resolve(b || file); }, 'image/jpeg', 0.85);
			};
			img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
			img.src = url;
		});
	}

	function fail($s, msg) {
		$s.addClass('has-error');
		setMsg($s, msg);
	}

	function upload($s, file, skipCompress) {
		setMsg($s, '');
		$s.removeClass('has-error').addClass('is-uploading');
		(skipCompress ? Promise.resolve(file) : compress(file)).then(function (blob) {
			var local = URL.createObjectURL(blob);
			var fd = new FormData();
			fd.append('action', 'codv_upload');
			fd.append('nonce', cfg.nonce);
			fd.append('slot', $s.data('slot'));
			fd.append('file', blob, 'photo.jpg');
			$.ajax({ url: cfg.ajax, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
				.done(function (r) {
					if (r && r.success) {
						$s.find('input.codv-id').val(r.data.id);
						$s.find('.codv-prev').attr('src', local);
						$s.addClass('has-image');
						$('#codv-panel .codv-error').text('');
					} else {
						URL.revokeObjectURL(local);
						fail($s, (r && r.data && r.data.message) || cfg.i18n.failed);
					}
				})
				.fail(function () {
					URL.revokeObjectURL(local);
					fail($s, cfg.i18n.failed);
				})
				.always(function () { $s.removeClass('is-uploading'); });
		});
	}

	function removeImg($s) {
		var id = $s.find('input.codv-id').val();
		var $img = $s.find('.codv-prev');
		var src = $img.attr('src');
		$s.find('input.codv-id').val('');
		$img.removeAttr('src');
		if (src && src.indexOf('blob:') === 0) { URL.revokeObjectURL(src); }
		$s.removeClass('has-image has-error');
		setMsg($s, '');
		if (id) { $.post(cfg.ajax, { action: 'codv_delete', nonce: cfg.nonce, id: id }); }
	}

	/* ---------- In-page camera (shutter + gallery icon) ---------- */
	var $cam = null, stream = null, camSlot = null, facing = 'environment';

	function buildCam() {
		if ($cam) { return; }
		$cam = $(
			'<div id="codv-cam" class="codv-cam" style="display:none">' +
				'<video playsinline autoplay muted></video>' +
				'<button type="button" class="codv-cam-close" aria-label="Close">' + ICON.close + '</button>' +
				'<div class="codv-cam-bar">' +
					'<button type="button" class="codv-cam-gal" aria-label="Gallery">' + ICON.gallery + '</button>' +
					'<button type="button" class="codv-cam-shot" aria-label="Capture"><i></i></button>' +
					'<button type="button" class="codv-cam-flip" aria-label="Flip">' + ICON.flip + '</button>' +
				'</div>' +
				'<input type="file" class="codv-cam-file" accept="image/*" style="display:none">' +
			'</div>'
		).appendTo('body');

		$cam.on('click', '.codv-cam-close', closeCam);
		$cam.on('click', '.codv-cam-gal', function () { $cam.find('.codv-cam-file').trigger('click'); });
		$cam.on('change', '.codv-cam-file', function () {
			var f = this.files && this.files[0];
			var $s = camSlot;
			this.value = '';
			closeCam();
			if (f && $s) { upload($s, f); }
		});
		$cam.on('click', '.codv-cam-flip', function () {
			facing = (facing === 'user') ? 'environment' : 'user';
			startStream();
		});
		$cam.on('click', '.codv-cam-shot', function () {
			var v = $cam.find('video')[0];
			if (!v || !v.videoWidth) { return; }
			var r = Math.min(1, 1600 / Math.max(v.videoWidth, v.videoHeight));
			var c = document.createElement('canvas');
			c.width = Math.round(v.videoWidth * r);
			c.height = Math.round(v.videoHeight * r);
			c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
			var $s = camSlot;
			c.toBlob(function (b) {
				closeCam();
				if (b && $s) { upload($s, b, true); }
			}, 'image/jpeg', 0.88);
		});
	}

	function stopStream() {
		if (stream) {
			$.each(stream.getTracks(), function (_, t) { t.stop(); });
			stream = null;
		}
	}

	function startStream() {
		stopStream();
		var video = $cam.find('video')[0];
		return navigator.mediaDevices.getUserMedia({
			video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } },
			audio: false
		}).then(function (s) {
			stream = s;
			video.srcObject = s;
			$cam.toggleClass('is-mirror', facing === 'user');
			var p = video.play();
			if (p && p.catch) { p.catch(function () {}); }
		});
	}

	function closeCam() {
		stopStream();
		if ($cam) { $cam.hide(); }
		$('html').removeClass('codv-lock');
	}

	// Fallback when the in-page camera is unavailable (HTTP site, in-app browser, permission denied):
	// a small sheet with a Camera icon (opens the phone camera) and a Gallery icon.
	var $sheet = null, sheetSlot = null;

	function buildSheet() {
		if ($sheet) { return; }
		$sheet = $(
			'<div class="codv-sheet" style="display:none">' +
				'<div class="codv-sheet-box">' +
					'<button type="button" class="codv-sheet-cam" aria-label="Camera">' + ICON.camera + '</button>' +
					'<button type="button" class="codv-sheet-gal" aria-label="Gallery">' + ICON.gallery + '</button>' +
				'</div>' +
				'<input type="file" class="codv-sheet-f-cam" accept="image/*" capture="environment" style="display:none">' +
				'<input type="file" class="codv-sheet-f-gal" accept="image/*" style="display:none">' +
			'</div>'
		).appendTo('body');

		$sheet.on('click', function (e) { if (e.target === this) { $sheet.hide(); } });
		$sheet.on('click', '.codv-sheet-cam', function () { $sheet.find('.codv-sheet-f-cam').trigger('click'); });
		$sheet.on('click', '.codv-sheet-gal', function () { $sheet.find('.codv-sheet-f-gal').trigger('click'); });
		$sheet.on('change', 'input[type=file]', function () {
			var f = this.files && this.files[0];
			var $s = sheetSlot;
			this.value = '';
			$sheet.hide();
			if (f && $s) { upload($s, f); }
		});
	}

	function nativePick($s) {
		buildSheet();
		sheetSlot = $s;
		var slot = $s.data('slot');
		$sheet.find('.codv-sheet-f-cam').attr('capture', (slot === 'selfie' || slot === 'photo') ? 'user' : 'environment');
		$sheet.css('display', 'flex');
	}

	function openCamera($s) {
		var canCam = window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
		if (!canCam) { nativePick($s); return; }
		buildCam();
		camSlot = $s;
		var slot = $s.data('slot');
		facing = (slot === 'selfie' || slot === 'photo') ? 'user' : 'environment';
		$cam.show();
		$('html').addClass('codv-lock');
		startStream().catch(function () {
			// Camera blocked or unavailable: fall back to the phone's own picker.
			closeCam();
			nativePick($s);
		});
	}

	$(document).on('click', '.codv-slot .codv-box', function () {
		var $s = $(this).closest('.codv-slot');
		if ($s.hasClass('has-image') || $s.hasClass('is-uploading')) { return; }
		openCamera($s);
	});
	$(document).on('keydown', '.codv-slot .codv-box', function (e) {
		if (e.which === 13 || e.which === 32) { e.preventDefault(); $(this).trigger('click'); }
	});
	$(document).on('click', '.codv-del', function (e) {
		e.preventDefault();
		e.stopPropagation();
		removeImg($(this).closest('.codv-slot'));
	});
	// Fallback native input (used only when the in-page camera is unavailable).
	$(document).on('change', '.codv-file', function () {
		var f = this.files && this.files[0];
		var $s = $(this).closest('.codv-slot');
		this.value = '';
		if (f) { upload($s, f); }
	});
	$(document).on('keydown', function (e) { if (e.which === 27 && $cam && $cam.is(':visible')) { closeCam(); } });

	/* ---------- NID / DOB inputs ---------- */
	$(document).on('input', '#codv-panel .codv-input[data-key="nid"]', function () {
		var v = toEn(this.value).replace(/\D+/g, '').slice(0, 17);
		if (this.value !== v) { this.value = v; }
		$(this).removeClass('has-error');
	});
	// DOB: type with keyboard (auto DD/MM/YYYY) or pick from the calendar icon.
	function fmtDob(v) {
		var d = toEn(v).replace(/\D+/g, '').slice(0, 8), out = d.slice(0, 2);
		if (d.length > 2) { out += '/' + d.slice(2, 4); }
		if (d.length > 4) { out += '/' + d.slice(4, 8); }
		return out;
	}
	function dobValid(v) {
		var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(toEn(v));
		if (!m) { return false; }
		var d = +m[1], mo = +m[2], y = +m[3], dt = new Date(y, mo - 1, d);
		return y >= 1900 && dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === d && dt <= new Date();
	}
	$(document).on('input', '#codv-panel .codv-input[data-key="dob"]', function () {
		var v = fmtDob(this.value);
		if (this.value !== v) { this.value = v; }
		$(this).removeClass('has-error');
	});
	$(document).on('click', '#codv-panel .codv-cal', function (e) {
		e.preventDefault();
		var $w = $(this).closest('.codv-field-dob'), pk = $w.find('.codv-datepick')[0];
		if (!pk) { return; }
		var cur = fmtDob($w.find('.codv-input').val());
		var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(cur);
		if (m && dobValid(cur)) { pk.value = m[3] + '-' + m[2] + '-' + m[1]; }
		try {
			if (pk.showPicker) { pk.showPicker(); } else { pk.focus(); pk.click(); }
		} catch (err) { pk.focus(); pk.click(); }
	});
	$(document).on('change', '#codv-panel .codv-datepick', function () {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(this.value);
		if (!m) { return; }
		$(this).closest('.codv-field-dob').find('.codv-input').val(m[3] + '/' + m[2] + '/' + m[1]).removeClass('has-error');
	});

	function showErr(t) { $('#codv-panel .codv-error').text(t); }

	/* ---------- Checkout guard ---------- */
	$form.on('checkout_place_order', function () {
		if (!isCod()) { return true; }
		var $pane = $('#codv-panel .codv-pane[data-pane="' + currentMode() + '"]');
		if ($pane.find('.codv-slot.is-uploading').length) {
			showErr(cfg.i18n.wait);
			return false;
		}
		var bad = false, msg = cfg.i18n.required;

		$pane.find('.codv-slot').each(function () {
			var $s = $(this);
			if (parseInt($s.data('required'), 10) === 1 && !$s.find('input.codv-id').val()) {
				$s.addClass('has-error');
				bad = true;
			}
		});
		$pane.find('.codv-input').each(function () {
			var $i = $(this), key = $i.data('key'), v = $.trim($i.val());
			var req = parseInt($i.data('required'), 10) === 1;
			var invalid = false;
			if (key === 'nid') {
				v = toEn(v).replace(/\D+/g, '');
				if ((req || v) && $.inArray(v.length, [10, 13, 17]) === -1) { invalid = true; if (v) { msg = cfg.i18n.nid; } }
			} else if (key === 'dob') {
				if ((req || v) && !dobValid(v)) { invalid = true; }
			} else if (req && !v) {
				invalid = true;
			}
			if (invalid) { $i.addClass('has-error'); bad = true; }
		});

		if (bad) {
			showErr(msg);
			var $p = $('#codv-panel');
			if ($p.length) { $('html, body').animate({ scrollTop: $p.offset().top - 120 }, 250); }
			return false;
		}
		showErr('');
		return true;
	});
});

/**
 * Festa dos Amigos 2026 — páginas do participante (pré-cadastro, login,
 * finalização e painel).
 * Máscaras, validação em tempo real, documentos (com otimização de fotos) e
 * envio AJAX com progresso, nova tentativa e renovação do nonce.
 *
 * Nada de onclick="…" no HTML: os comportamentos são ligados aqui, por
 * atributos data-*. Sem JavaScript, os formulários continuam funcionando
 * pelo envio tradicional (admin-post.php).
 */
(function () {
	'use strict';

	var cfg = window.festaForms || {};

	/* ---------------- Máscaras ---------------- */

	var masks = {
		cpf: function (v) {
			v = v.replace(/\D/g, '').slice(0, 11);
			return v
				.replace(/(\d{3})(\d)/, '$1.$2')
				.replace(/(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
				.replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
		},
		phone: function (v) {
			v = v.replace(/\D/g, '').slice(0, 11);
			if (v.length > 10) {
				return v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
			}
			return v
				.replace(/(\d{2})(\d)/, '($1) $2')
				.replace(/(\d{4})(\d{1,4})$/, '$1-$2');
		}
	};

	/* ---------------- Validadores ---------------- */

	function validCPF(cpf) {
		cpf = cpf.replace(/\D/g, '');
		if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
		var s = 0, i, r;
		for (i = 0; i < 9; i++) s += parseInt(cpf.charAt(i), 10) * (10 - i);
		r = (s * 10) % 11;
		if (r === 10) r = 0;
		if (r !== parseInt(cpf.charAt(9), 10)) return false;
		s = 0;
		for (i = 0; i < 10; i++) s += parseInt(cpf.charAt(i), 10) * (11 - i);
		r = (s * 10) % 11;
		if (r === 10) r = 0;
		return r === parseInt(cpf.charAt(10), 10);
	}

	function validEmail(v) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
	}

	/* ---------------- Helpers ---------------- */

	function humanSize(b) {
		if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
		return Math.max(1, Math.round(b / 1024)) + ' KB';
	}

	function fieldWrap(el) {
		return el && el.closest ? el.closest('.festa-field') : null;
	}

	function setError(el, msg) {
		var wrap = fieldWrap(el) || el;
		if (!wrap || !wrap.querySelector) return;
		var p = wrap.querySelector('.festa-error');
		wrap.classList.remove('festa-valid');
		if (msg) {
			wrap.classList.add('festa-invalid');
			if (p) { p.textContent = msg; p.hidden = false; }
		} else {
			wrap.classList.remove('festa-invalid');
			if (p) { p.hidden = true; }
		}
	}

	function setValid(el) {
		var wrap = fieldWrap(el);
		if (!wrap) return;
		wrap.classList.remove('festa-invalid');
		wrap.classList.add('festa-valid');
		var p = wrap.querySelector('.festa-error');
		if (p) p.hidden = true;
	}

	/**
	 * Regra de validação de um campo de texto. Retorna '' quando válido.
	 */
	function fieldMessage(el) {
		var v = el.value.trim();
		var mask = el.getAttribute('data-mask');

		if (!v) {
			return el.hasAttribute('data-required') ? 'Este campo é obrigatório' : '';
		}
		if (el.type === 'email' && !validEmail(v)) {
			return 'Informe um e-mail válido, por exemplo nome@dominio.com';
		}
		if (mask === 'cpf') {
			if (v.replace(/\D/g, '').length < 11) return 'CPF incompleto — digite os 11 números';
			if (!validCPF(v)) return 'CPF inválido — confira os números digitados';
		}
		if (mask === 'phone' && v.replace(/\D/g, '').length < 10) {
			return 'Telefone incompleto — use o formato (00) 00000-0000';
		}
		if (el.type === 'number') {
			var n = parseInt(v, 10);
			if (isNaN(n) || (el.min !== '' && n < parseInt(el.min, 10))) return 'Informe um número a partir de ' + (el.min || '1');
			if (el.max !== '' && n > parseInt(el.max, 10)) return 'O máximo é ' + el.max;
			return '';
		}
		if (el.type === 'password') {
			var min = parseInt(el.getAttribute('data-min') || '0', 10);
			if (min && el.value.length < min) return 'Use pelo menos ' + min + ' caracteres';
			var same = el.getAttribute('data-match');
			var other = same ? el.form.querySelector('[name="' + same + '"]') : null;
			if (other && other.value !== el.value) return 'As duas senhas precisam ser iguais';
			return '';
		}
		if (v.length < 2) {
			return 'Resposta muito curta';
		}
		return '';
	}

	/* ---------------- Documentos ---------------- */

	function fileKind(file) {
		var n = (file.name || '').toLowerCase();
		var t = (file.type || '').toLowerCase();
		if (t === 'application/pdf' || /\.pdf$/.test(n)) return 'pdf';
		if (/^image\/(jpeg|png|webp|heic|heif)$/.test(t) || /\.(jpe?g|png|webp|heic|heif)$/.test(n)) return 'image';
		return '';
	}

	function fileMessage(input) {
		var file = input.files && input.files[0];
		if (!file) {
			return input.hasAttribute('data-required') ? 'Envie este documento' : '';
		}
		var kind = fileKind(file);
		var accept = input.getAttribute('data-accept');
		if (accept === 'image') {
			if (kind !== 'image') return 'Envie uma foto (JPG, PNG, WEBP ou HEIC)';
		} else if (accept === 'pdf') {
			if (kind !== 'pdf') return 'Envie um arquivo PDF';
		} else if (!kind) {
			return 'Formato não aceito — envie uma imagem ou um PDF';
		}
		if (cfg.maxBytes && file.size > cfg.maxBytes) {
			return 'Arquivo muito grande (' + humanSize(file.size) + '). O limite é ' + (cfg.maxLabel || humanSize(cfg.maxBytes)) + '.';
		}
		return '';
	}

	/**
	 * Fotos de celular costumam ter 3–8 MB. Reduz para no máximo 2000 px
	 * em JPEG, o que mantém o documento legível e deixa o envio bem mais
	 * rápido em rede móvel. Em qualquer falha, usa o arquivo original.
	 */
	function compressImage(file) {
		return new Promise(function (resolve) {
			var canvasOk = !!document.createElement('canvas').toBlob;
			if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1200000 || !window.URL || !canvasOk) {
				resolve(file);
				return;
			}
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				var max = 2000;
				var scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
				var canvas = document.createElement('canvas');
				canvas.width = Math.round(img.naturalWidth * scale);
				canvas.height = Math.round(img.naturalHeight * scale);
				var ctx = canvas.getContext('2d');
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
				ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
				URL.revokeObjectURL(url);
				canvas.toBlob(function (blob) {
					if (!blob || blob.size >= file.size) {
						resolve(file);
						return;
					}
					try {
						resolve(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
					} catch (e) {
						resolve(file);
					}
				}, 'image/jpeg', 0.85);
			};
			img.onerror = function () {
				URL.revokeObjectURL(url);
				resolve(file);
			};
			img.src = url;
		});
	}

	/** Troca o arquivo do input (para o envio tradicional também usar a versão otimizada). */
	function setInputFile(input, file) {
		try {
			var dt = new DataTransfer();
			dt.items.add(file);
			input.files = dt.files;
			return true;
		} catch (e) {
			return false;
		}
	}

	function renderFile(input) {
		var wrap = fieldWrap(input);
		if (!wrap) return;
		var empty = wrap.querySelector('.festa-drop-empty');
		var filled = wrap.querySelector('.festa-drop-filled');
		var thumb = wrap.querySelector('.festa-file-thumb');
		var pdf = wrap.querySelector('.festa-file-pdf');
		var file = input.files && input.files[0];

		if (thumb && thumb._url) {
			URL.revokeObjectURL(thumb._url);
			thumb._url = null;
		}

		if (!file) {
			empty.hidden = false;
			filled.hidden = true;
			return;
		}

		empty.hidden = true;
		filled.hidden = false;
		wrap.querySelector('.festa-file-name').textContent = file.name;
		wrap.querySelector('.festa-file-size').textContent = input._festaBusy ? 'Otimizando imagem…' : humanSize(file.size);

		var kind = fileKind(file);
		pdf.hidden = kind !== 'pdf';
		thumb.hidden = true;
		if (kind === 'image' && window.URL) {
			thumb._url = URL.createObjectURL(file);
			thumb.onload = function () { thumb.hidden = false; };
			thumb.onerror = function () { thumb.hidden = true; }; // HEIC fora do Safari
			thumb.src = thumb._url;
		}
	}

	function clearFile(input) {
		input.value = '';
		input._festaBusy = null;
		renderFile(input);
	}

	/* ---------------- Nonce ---------------- */

	/** Páginas abertas há muito tempo (ou vindas de cache) carregam um nonce vencido. */
	function renewNonce() {
		var fd = new FormData();
		fd.append('action', 'festa_refresh_nonce');
		return fetch(cfg.nonceUrl || '/wp-admin/admin-ajax.php?action=festa_refresh_nonce', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success && res.data && res.data.nonce) {
					cfg.nonce = res.data.nonce;
					document.querySelectorAll('form[data-festa-form] input[name="nonce"]').forEach(function (el) {
						el.value = cfg.nonce;
					});
					return true;
				}
				return false;
			})
			.catch(function () { return false; });
	}

	/** Endereço da página sem os avisos do envio tradicional (?festa_ok / ?festa_erro / ?festa_sig). */
	function cleanUrl() {
		var search = location.search.replace(/([?&])festa_(ok|erro|sig)=[^&]*/g, '$1').replace(/&{2,}/g, '&').replace(/[?&]+$/, '').replace(/\?&/, '?');
		return location.pathname + search;
	}

	/* ---------------- Formulários ---------------- */

	function initForm(form) {
		var textInputs = form.querySelectorAll('input.festa-input, textarea.festa-input');
		var fileInputs = form.querySelectorAll('input.festa-file-input');
		var radioGroups = form.querySelectorAll('.festa-field[data-required-radio]');
		var checkFields = form.querySelectorAll('.festa-field[data-required-check]');

		/* ---- Máscaras ---- */

		form.querySelectorAll('[data-mask]').forEach(function (input) {
			var mask = masks[input.getAttribute('data-mask')];
			if (!mask) return;
			input.addEventListener('input', function () {
				input.value = mask(input.value);
			});
		});

		/* ---- Validação em tempo real ---- */

		function check(el) {
			var msg = fieldMessage(el);
			if (msg) {
				setError(el, msg);
			} else if (el.value.trim()) {
				setValid(el);
			} else {
				setError(el, '');
			}
		}

		textInputs.forEach(function (el) {
			el.addEventListener('blur', function () {
				el._festaTouched = true;
				check(el);
			});
			el.addEventListener('input', function () {
				if (el._festaTouched) check(el);
			});
		});

		/* ---- Documentos ---- */

		fileInputs.forEach(function (input) {
			var drop = input.closest('.festa-drop');

			input.addEventListener('change', function () {
				var file = input.files && input.files[0];
				var msg = fileMessage(input);
				// Formato errado: não adianta otimizar, só avisa.
				if (file && msg && !/grande/.test(msg)) {
					setError(input, msg);
					clearFile(input);
					return;
				}
				if (!file) {
					renderFile(input);
					return;
				}

				input._festaBusy = compressImage(file).then(function (out) {
					if (out !== file) setInputFile(input, out);
					input._festaBusy = null;
					renderFile(input);
					var m = fileMessage(input);
					if (m) {
						setError(input, m);
						clearFile(input);
					} else {
						setValid(input);
					}
				});
				renderFile(input);
				setError(input, '');
			});

			var remove = fieldWrap(input).querySelector('.festa-file-remove');
			if (remove) {
				remove.addEventListener('click', function () {
					clearFile(input);
					setError(input, '');
					input.focus();
				});
			}

			['dragenter', 'dragover'].forEach(function (ev) {
				input.addEventListener(ev, function () { drop.classList.add('is-drag'); });
			});
			['dragleave', 'drop'].forEach(function (ev) {
				input.addEventListener(ev, function () { drop.classList.remove('is-drag'); });
			});
		});

		/* ---- Grupos de escolha e caixas de aceite obrigatórios ---- */

		function checkedValue(name) {
			var c = form.querySelector('input[name="' + name + '"]:checked');
			return c ? c.value : '';
		}

		radioGroups.forEach(function (group) {
			var name = group.getAttribute('data-required-radio');
			form.querySelectorAll('input[name="' + name + '"]').forEach(function (r) {
				r.addEventListener('change', function () { setError(group, ''); });
			});
		});

		checkFields.forEach(function (field) {
			var box = field.querySelector('input[type="checkbox"]');
			box.addEventListener('change', function () {
				if (box.checked) setError(field, '');
			});
		});

		/* ---- Validação no envio ---- */

		function validate() {
			textInputs.forEach(function (el) {
				el._festaTouched = true;
				var msg = fieldMessage(el);
				if (msg) setError(el, msg);
				else if (el.value.trim()) setValid(el);
			});

			radioGroups.forEach(function (group) {
				if (!checkedValue(group.getAttribute('data-required-radio'))) {
					setError(group, 'Selecione uma das opções');
				}
			});

			fileInputs.forEach(function (el) {
				var msg = fileMessage(el);
				if (msg) setError(el, msg);
			});

			checkFields.forEach(function (field) {
				if (!field.querySelector('input[type="checkbox"]').checked) {
					setError(field, 'Marque esta caixa para continuar');
				}
			});

			// Campos em ordem de tela: leva ao primeiro com erro.
			var firstBad = form.querySelector('.festa-field.festa-invalid');
			if (firstBad) {
				firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
				var focusable = firstBad.querySelector('input, textarea');
				if (focusable && focusable.type !== 'file') {
					window.setTimeout(function () { focusable.focus({ preventScroll: true }); }, 350);
				}
				return false;
			}
			return true;
		}

		/* ---- Envio ---- */

		var formError = form.querySelector('.festa-form-error');
		var submitBtn = form.querySelector('.festa-submit');
		var submitLabel = submitBtn ? submitBtn.textContent : 'Enviar';
		var nonceInput = form.querySelector('input[name="nonce"]');
		var actionInput = form.querySelector('input[name="action"]');
		var base = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
		// A action vai na query string para que o WordPress a encontre mesmo
		// se o PHP descartar o corpo de um envio grande demais.
		var ajaxUrl = base + '?action=' + encodeURIComponent(actionInput ? actionInput.value : '');

		function showFormError(msg) {
			if (!formError) return;
			formError.textContent = msg;
			formError.hidden = false;
			formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		function busy(text) {
			if (!submitBtn) return;
			submitBtn.disabled = true;
			submitBtn.textContent = text;
		}

		function restoreButton() {
			if (!submitBtn) return;
			submitBtn.disabled = false;
			submitBtn.textContent = submitLabel;
		}

		function buildData() {
			var fd = new FormData(form);
			if (cfg.nonce && !nonceInput) fd.set('nonce', cfg.nonce);
			return fd;
		}

		/**
		 * POST com progresso de upload e tempo limite (redes móveis costumam
		 * "pendurar" a conexão sem nunca falhar).
		 */
		function postOnce(fd) {
			return new Promise(function (resolve, reject) {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxUrl, true);
				xhr.timeout = 180000;
				xhr.withCredentials = true;
				if (xhr.upload) {
					xhr.upload.onprogress = function (e) {
						if (e.lengthComputable && e.total > 200000) {
							var pct = Math.min(99, Math.round((e.loaded / e.total) * 100));
							busy('Enviando… ' + pct + '%');
						}
					};
				}
				xhr.onload = function () {
					try {
						resolve({ res: JSON.parse(xhr.responseText), status: xhr.status });
					} catch (e) {
						// Proxy/firewall devolveu HTML: 413 = corpo grande demais.
						reject(new Error(xhr.status === 413 ? 'grande' : 'resposta-invalida'));
					}
				};
				xhr.onerror = function () { reject(new Error('rede')); };
				xhr.ontimeout = function () {
					var err = new Error('timeout');
					err.name = 'AbortError';
					reject(err);
				};
				xhr.send(fd);
			});
		}

		/**
		 * O que fazer quando o servidor confirma: recarregar a página (o
		 * servidor decide o que mostrar agora), ir para outro endereço, ou
		 * trocar o cartão do formulário pelo painel de confirmação.
		 */
		function showSuccess(data) {
			var mode = form.getAttribute('data-festa-success') || 'reload';

			if (mode === 'redirect' && data.redirect) {
				window.location.assign(data.redirect);
				return true;
			}
			if (mode !== 'panel') {
				window.location.replace(cleanUrl());
				return true;
			}

			var panel = document.querySelector(form.getAttribute('data-festa-panel'));
			if (!panel) {
				window.location.replace(cleanUrl());
				return true;
			}
			panel.querySelectorAll('[data-festa-fill]').forEach(function (el) {
				var value = data[el.getAttribute('data-festa-fill')];
				if (value !== undefined && value !== null) el.textContent = value;
			});
			var extra = form.getAttribute('data-festa-hide');
			if (extra) {
				document.querySelectorAll(extra).forEach(function (el) { el.hidden = true; });
			}
			(form.closest('.festa-card') || form).hidden = true;
			panel.hidden = false;
			panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
			return false;
		}

		function handleResult(out) {
			var res = out.res;
			if (res && res.success) {
				// Ao recarregar/redirecionar, o botão fica ocupado até a página trocar.
				if (!showSuccess(res.data || {})) restoreButton();
				return;
			}
			var msg = res && res.data && res.data.message
				? res.data.message
				: 'Não foi possível enviar. Verifique os campos e tente novamente.';
			if (res && res.data && res.data.field) {
				var field = res.data.field;
				var el = form.querySelector('.festa-field[data-required-radio="' + field + '"]')
					|| form.querySelector('.festa-field[data-required-check="' + field + '"]')
					|| form.querySelector('[name="' + field + '"]');
				if (el) setError(el, msg);
			}
			showFormError(msg);
			restoreButton();
		}

		/**
		 * Tenta enviar; em falha de rede repete até 2 vezes. Esgotadas as
		 * tentativas, envia pelo formulário tradicional (sem JS), que funciona
		 * mesmo onde o XHR é bloqueado (navegador do WhatsApp).
		 */
		function submitWithRetry(attempt) {
			busy('Enviando…');
			postOnce(buildData())
				.then(function (out) {
					var expirou = out.status === 403 && out.res && out.res.data && out.res.data.code === 'nonce';
					if (expirou && attempt === 0) {
						renewNonce().then(function (renovou) {
							if (renovou) {
								submitWithRetry(1);
							} else {
								handleResult(out);
							}
						});
						return;
					}
					handleResult(out);
				})
				.catch(function (e) {
					if (e && e.message === 'grande') {
						showFormError('Os arquivos, somados, passam do limite aceito pelo servidor. Envie fotos menores ou PDFs mais leves.');
						restoreButton();
						return;
					}
					if (attempt < 2) {
						window.setTimeout(function () { submitWithRetry(attempt + 1); }, attempt === 0 ? 1500 : 4000);
						return;
					}
					if (form.getAttribute('action')) {
						busy('Enviando…');
						form.submit();
						return;
					}
					showFormError(
						e && e.name === 'AbortError'
							? 'A conexão demorou demais. Verifique sua internet e tente novamente.'
							: 'Não foi possível enviar agora. Verifique sua conexão e tente novamente.'
					);
					restoreButton();
				});
		}

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (formError) formError.hidden = true;

			// Espera terminar a otimização de fotos escolhidas agora há pouco.
			var pending = [];
			fileInputs.forEach(function (el) { if (el._festaBusy) pending.push(el._festaBusy); });
			if (pending.length) {
				busy('Preparando documentos…');
				Promise.all(pending).then(function () {
					restoreButton();
					form.dispatchEvent(new Event('submit', { cancelable: true }));
				});
				return;
			}

			if (!validate()) {
				// validate() já rolou até o primeiro campo com erro.
				if (formError) {
					formError.textContent = 'Há campos sem resposta ou com erro. Confira os destacados.';
					formError.hidden = false;
				}
				return;
			}

			var total = 0;
			fileInputs.forEach(function (el) { if (el.files && el.files[0]) total += el.files[0].size; });
			if (cfg.maxPostBytes && total > cfg.maxPostBytes - 524288) {
				showFormError('Os documentos somam ' + humanSize(total) + ', acima do limite do servidor ('
					+ humanSize(cfg.maxPostBytes) + '). Envie PDFs mais leves ou fotos em vez de digitalizações grandes.');
				return;
			}

			submitWithRetry(0);
		});
	}

	document.querySelectorAll('form[data-festa-form]').forEach(initForm);

	/* ---------------- Copiar (senha de acesso) ---------------- */

	document.addEventListener('click', function (ev) {
		var btn = ev.target && ev.target.closest ? ev.target.closest('[data-festa-copy]') : null;
		if (!btn) return;
		var target = document.querySelector(btn.getAttribute('data-festa-copy'));
		if (!target) return;
		var text = target.textContent.trim();
		var done = function () {
			var label = btn.textContent;
			btn.textContent = 'Copiado!';
			window.setTimeout(function () { btn.textContent = label; }, 2000);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done).catch(function () {});
			return;
		}
		// Navegadores antigos ou página sem HTTPS: seleciona para a pessoa copiar.
		var range = document.createRange();
		range.selectNodeContents(target);
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
	});
})();

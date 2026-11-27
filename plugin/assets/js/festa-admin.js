/**
 * Festa dos Amigos 2026 — telas de gerenciamento (wp-admin e portal externo).
 * Confirmações e seleção em massa.
 *
 * Nada de onclick="…" no HTML: os comportamentos são ligados aqui, por
 * atributos data-*. O portal externo proíbe scripts embutidos na página.
 */
(function () {
	'use strict';

	/* ---- Confirmações ----
	 * data-festa-confirm="pergunta" num botão/link pede confirmação ao clicar;
	 * num <form>, ao enviar. */

	document.addEventListener('click', function (ev) {
		var el = ev.target && ev.target.closest ? ev.target.closest('button[data-festa-confirm], a[data-festa-confirm]') : null;
		if (el && !window.confirm(el.getAttribute('data-festa-confirm'))) {
			ev.preventDefault();
		}
	});

	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		var msg = form && form.getAttribute ? form.getAttribute('data-festa-confirm') : null;
		if (msg && !window.confirm(msg)) {
			ev.preventDefault();
		}
	});

	/* ---- Ações em massa ----
	 * As caixas de seleção ficam na tabela e pertencem ao formulário pelo
	 * atributo form="festa-bulk-form". */

	var bulk = document.getElementById('festa-bulk-form');
	if (!bulk) return;

	var boxes = function () {
		return document.querySelectorAll('input[name="ids[]"]');
	};
	var all = document.querySelector('.festa-check-all');
	if (all) {
		all.addEventListener('change', function () {
			boxes().forEach(function (c) { c.checked = all.checked; });
		});
	}
	bulk.addEventListener('submit', function (ev) {
		var action = bulk.querySelector('#festa-bulk-action').value;
		var n = 0;
		boxes().forEach(function (c) { if (c.checked) n++; });
		if (!action || !n) {
			ev.preventDefault();
			window.alert(!n ? 'Selecione ao menos uma inscrição.' : 'Escolha uma ação.');
			return;
		}
		if (action === 'delete' && !window.confirm('Excluir definitivamente ' + n + ' inscrição(ões) e os seus documentos?')) {
			ev.preventDefault();
		}
	});
})();

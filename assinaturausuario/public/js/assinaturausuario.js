/* Plugin Assinatura de Usuário - editor da assinatura (variáveis, modelo, prévia ao vivo),
 * confirmação de remoção, abas, pesquisa e multiselect da configuração. */
(function () {
    'use strict';

    if (window.assinaturausuarioCarregado) {
        return;
    }
    window.assinaturausuarioCarregado = true;

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var editor = function (id) {
        return window.tinymce ? window.tinymce.get(id || 'assinaturausuario_editor') : null;
    };

    // ------------------------------------------------------------------ prévia ao vivo

    var token = function (raiz) {
        if (raiz && raiz.dataset.token) {
            return raiz.dataset.token;
        }
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    };

    var atualizarPrevia = function (raiz, ed) {
        var alvo = raiz.querySelector('[data-assinaturausuario-previa]');
        if (!alvo) {
            return;
        }
        var fd = new FormData();
        fd.append('action', 'previa');
        fd.append('users_id', raiz.dataset.usuario || '0');
        fd.append('conteudo', ed.getContent());
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        var t = token(raiz);
        if (t) {
            fd.append('_glpi_csrf_token', t);
            cab['X-Glpi-Csrf-Token'] = t;
        }
        fetch(raiz.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (texto) {
                var r = lerJson(texto);
                if (r.new_token) {
                    raiz.dataset.token = r.new_token;
                }
                if (r.success) {
                    alvo.innerHTML = r.html || '<span class="assinaturausuario-pequeno">Sem assinatura.</span>';
                }
            });
    };

    var ligarEditor = function () {
        document.querySelectorAll('[data-assinaturausuario-pref]').forEach(function (raiz) {
            if (raiz.dataset.ligado === '1') {
                return;
            }
            var ed = editor();
            if (!ed || !ed.initialized) {
                return;
            }
            raiz.dataset.ligado = '1';
            var espera = null;
            ed.on('input change undo redo SetContent', function () {
                clearTimeout(espera);
                espera = setTimeout(function () { atualizarPrevia(raiz, ed); }, 700);
            });
        });
    };
    setInterval(ligarEditor, 600);

    // ------------------------------------------------------------------ cliques

    document.addEventListener('click', function (e) {
        var variavel = e.target.closest('[data-assinaturausuario-variavel]');
        if (variavel) {
            e.preventDefault();
            var ed = editor(variavel.dataset.editor);
            if (ed) {
                ed.focus();
                ed.insertContent(variavel.dataset.assinaturausuarioVariavel);
            }
            return;
        }
        var modelo = e.target.closest('[data-assinaturausuario-modelo]');
        if (modelo) {
            e.preventDefault();
            var tpl = modelo.parentNode.querySelector('[data-assinaturausuario-modelo-html]');
            var ed2 = editor();
            if (tpl && ed2) {
                ed2.setContent(tpl.innerHTML);
                ed2.fire ? ed2.fire('change') : ed2.dispatch('change');
            }
            return;
        }
        // Confirmação no próprio botão (sem confirm do navegador): o segundo clique confirma
        var confirmar = e.target.closest('[data-assinaturausuario-confirmar]');
        if (confirmar && confirmar.dataset.confirmado !== '1') {
            e.preventDefault();
            confirmar.dataset.confirmado = '1';
            var span = confirmar.querySelector('span');
            var original = span ? span.textContent : '';
            if (span) {
                span.textContent = confirmar.dataset.assinaturausuarioConfirmar;
            }
            confirmar.classList.add('assinaturausuario-confirmando');
            setTimeout(function () {
                confirmar.dataset.confirmado = '';
                confirmar.classList.remove('assinaturausuario-confirmando');
                if (span) {
                    span.textContent = original;
                }
            }, 4000);
            return;
        }
        var aba = e.target.closest('.assinaturausuario-abas [data-aba]');
        if (aba) {
            e.preventDefault();
            var pagina = aba.closest('.assinaturausuario-pagina');
            pagina.querySelectorAll('.assinaturausuario-abas [data-aba]').forEach(function (l) { l.classList.toggle('active', l === aba); });
            pagina.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== aba.dataset.aba; });
            pagina.querySelectorAll('input[name="aba"]').forEach(function (i) { i.value = aba.dataset.aba; });
            var url = new URL(window.location.href);
            url.searchParams.set('aba', aba.dataset.aba);
            window.history.replaceState(null, '', url.toString());
        }
    });

    // ------------------------------------------------------------------ multiselect e pesquisa

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.assinaturausuario-ms-opcao'));
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var nome = function (o) { return o.querySelector('.assinaturausuario-ms-rotulo').childNodes[0].textContent.trim(); };
        var texto = ms.querySelector('.assinaturausuario-ms-texto');
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Selecione...';
        } else if (marcadas.length <= 3) {
            texto.textContent = marcadas.map(nome).join(', ');
        } else {
            texto.textContent = marcadas.slice(0, 2).map(nome).join(', ') + ' e mais ' + (marcadas.length - 2);
        }
        ms.querySelector('.assinaturausuario-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-assinaturausuario-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.assinaturausuario-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.assinaturausuario-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-assinaturausuario-ms-abrir]');
        document.querySelectorAll('[data-assinaturausuario-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.assinaturausuario-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.assinaturausuario-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.assinaturausuario-ms-busca')) {
            filtrarMs(e.target.closest('[data-assinaturausuario-ms]'));
        } else if (e.target.matches('[data-assinaturausuario-busca-tabela]')) {
            var termo = e.target.value.trim().toLowerCase();
            var raiz = e.target.closest('.card-body') || document;
            raiz.querySelectorAll('tr[data-search]').forEach(function (tr) {
                tr.hidden = termo !== '' && tr.dataset.search.indexOf(termo) < 0;
            });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.assinaturausuario-ms-busca, [data-assinaturausuario-busca-tabela]')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-assinaturausuario-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-assinaturausuario-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.assinaturausuario-ms-opcao')) {
            e.target.closest('.assinaturausuario-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.assinaturausuario-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
                busca.focus();
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
    });

    var iniciar = function () {
        document.querySelectorAll('[data-assinaturausuario-ms]').forEach(atualizarMs);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();

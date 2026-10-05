<?php

/**
 * Plugin Assinatura de Usuário - configuração (marketplace). Cada aba é um formulário próprio que
 * faz POST para esta mesma página.
 */

Session::checkLoginUser();

global $DB, $CFG_GLPI;
$C = PluginAssinaturausuarioConfig::class;
$A = PluginAssinaturausuarioAssinatura::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$ABAS = [
    'geral'    => ['ti ti-settings', 'Geral'],
    'modelo'   => ['ti ti-template', 'Assinatura padrão'],
    'situacao' => ['ti ti-chart-bar', 'Situação'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'geral');
if (!isset($ABAS[$aba])) {
    $aba = 'geral';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_geral':
            $C::setArrayConfig('perfis', $C::idsPost('perfis'));
            $C::setArrayConfig('tipos', array_values(array_intersect(array_keys($C::TIPOS), array_map('strval', (array) ($_POST['tipos'] ?? [])))));
            $C::setConfig('formato', ($_POST['formato'] ?? '') === 'base64' ? 'base64' : 'link');
            $C::setConfig('outros_plugins', empty($_POST['outros_plugins']) ? '0' : '1');
            $C::setConfig('largura_maxima', (string) max(100, min(1200, (int) ($_POST['largura_maxima'] ?? 600))));
            Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
            break;

        case 'salvar_modelo':
            $uploads = $C::uploadsPost('modelo');
            $modelo = $A::normalizar((string) ($_POST['modelo'] ?? ''), 0, $uploads);
            $C::setConfig('modelo', $A::vazio($modelo) ? '' : $modelo);
            $C::setConfig('usar_modelo', empty($_POST['usar_modelo']) ? '0' : '1');
            foreach ($uploads as $arquivo) {
                @unlink($arquivo);
            }
            Session::addMessageAfterRedirect('Assinatura padrão salva.', false, INFO);
            break;
    }
}

Html::header('Assinatura de Usuário', $_SERVER['PHP_SELF'] ?? '', 'config', 'plugin');

$form = fn(string $acao, string $abaForm) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="assinaturausuario-form">'
    . '<input type="hidden" name="save_action" value="' . $e($acao) . '"><input type="hidden" name="aba" value="' . $e($abaForm) . '">';
$salvar = '<div class="assinaturausuario-rodape-form"><button type="submit" class="btn btn-sm assinaturausuario-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card assinaturausuario-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$explicacao = fn(string $texto) => '<p class="assinaturausuario-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';
$campo = fn(string $rotulo, string $controle, string $dica = '') => '<div class="assinaturausuario-campo"><label>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$switch = fn(string $nome, string $rotulo, string $descricao, bool $ligado) => '<div class="form-check form-switch assinaturausuario-switch assinaturausuario-switch-linha"><input type="hidden" name="' . $nome . '" value="0">'
    . '<input class="form-check-input" type="checkbox" id="assinaturausuario-cfg-' . $nome . '" name="' . $nome . '" value="1"' . ($ligado ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="assinaturausuario-cfg-' . $nome . '"><span>' . $e($rotulo) . '</span><small>' . $descricao . '</small></label></div>';

echo '<div class="assinaturausuario-pagina assinaturausuario-config">';
echo '<ul class="nav nav-tabs assinaturausuario-abas">';
foreach ($ABAS as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- Geral
echo '<div data-aba-painel="geral"' . ($aba !== 'geral' ? ' hidden' : '') . '>' . $form('salvar_geral', 'geral');
$tipos = $C::tiposAtivos();
$listaTipos = '';
foreach ($C::TIPOS as $t => [$rotulo, $icone]) {
    $listaTipos .= '<label class="assinaturausuario-opcao"><input type="checkbox" class="assinaturausuario-check" name="tipos[]" value="' . $e($t) . '"' . (in_array($t, $tipos, true) ? ' checked' : '') . '><i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</label>';
}
$formato = (string) $C::getConfig('formato');
$opcoesFormato = '';
foreach (['link' => ['Link para a imagem (recomendado)', 'Leve no banco e estável na edição. Nos e-mails, a imagem é carregada do GLPI (' . $e($CFG_GLPI['url_base'] ?: 'URL do GLPI não configurada') . '): quem lê fora da rede não a vê.'],
          'base64' => ['Imagem embutida no texto (base64)', 'Cada acompanhamento leva uma cópia da imagem: aparece em qualquer lugar, mas ocupa mais espaço no banco e nos e-mails.']] as $v => [$rotulo, $dica]) {
    $opcoesFormato .= '<label class="assinaturausuario-radio"><input type="radio" name="formato" value="' . $v . '"' . ($formato === $v ? ' checked' : '') . '><span><strong>' . $e($rotulo) . '</strong><small>' . $dica . '</small></span></label>';
}
$corpo = $explicacao('A assinatura entra uma única vez, só em itens <strong>novos</strong> enviados pelos formulários nativos da linha do tempo. Edições, API, coletor de e-mail, tarefas automáticas e itens criados por outros plugins não recebem assinatura.')
    . '<div class="assinaturausuario-grade">'
    . '<div>'
    . $campo('Perfis que usam assinatura', $C::multiselect('perfis', $C::listarPerfis(), $C::ids('perfis'), 'Todos os perfis'), 'Vazio: todos os perfis.')
    . $campo('Onde assinar', '<div class="assinaturausuario-tipos">' . $listaTipos . '<input type="hidden" name="tipos[]" value=""></div>', 'Cada pessoa ainda escolhe, nas preferências, em quais destes quer assinar.')
    . $switch('outros_plugins', 'Assinar também itens criados por outros plugins', 'Desligado (recomendado): só os formulários do GLPI assinam, o que evita assinatura em mensagens automáticas ou repetidas. O bloco já assinado nunca é repetido.', $C::getConfig('outros_plugins') === '1')
    . '</div><div>'
    . $campo('Formato das imagens', '<div class="assinaturausuario-radios">' . $opcoesFormato . '</div>')
    . $campo('Largura máxima das imagens (px)', '<input type="number" class="form-control form-control-sm assinaturausuario-numero" name="largura_maxima" min="100" max="1200" value="' . (int) $C::largura() . '">', 'Imagens maiores são reduzidas ao salvar a assinatura.')
    . '</div></div>';
echo $card('ti ti-signature', 'Assinatura de usuário', $corpo) . $salvar;
echo Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Assinatura padrão
echo '<div data-aba-painel="modelo"' . ($aba !== 'modelo' ? ' hidden' : '') . '>' . $form('salvar_modelo', 'modelo');
echo '<div class="card assinaturausuario-card"><div class="card-header"><h5><i class="ti ti-template"></i> Assinatura padrão da empresa</h5></div><div class="card-body">';
echo $explicacao('Modelo com o visual da empresa. As pessoas podem copiá-lo para a própria assinatura com o botão "Usar assinatura padrão" e, se a opção abaixo estiver ligada, ele vale para quem ainda não salvou uma assinatura própria.');
echo $switch('usar_modelo', 'Usar para quem não tem assinatura própria', 'Quem salvar uma assinatura própria (ou desligá-la) deixa de usar o padrão.', $C::getConfig('usar_modelo') === '1');
echo '<div class="assinaturausuario-variaveis"><span class="assinaturausuario-rotulo">Variáveis:</span>';
foreach ($C::VARIAVEIS as $var => $descricao) {
    echo '<button type="button" class="assinaturausuario-variavel" data-assinaturausuario-variavel="' . $e($var) . '" data-editor="assinaturausuario_modelo" title="' . $e($descricao) . '">' . $e($var) . '</button>';
}
echo '</div>';
Html::textarea([
    'name'            => 'modelo',
    'editor_id'       => 'assinaturausuario_modelo',
    'value'           => (string) $C::getConfig('modelo'),
    'enable_richtext' => true,
    'enable_images'   => true,
    'cols'            => 100,
    'rows'            => 10,
]);
echo '</div></div>' . $salvar;
echo Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Situação
echo '<div data-aba-painel="situacao"' . ($aba !== 'situacao' ? ' hidden' : '') . '>';
$total = countElementsInTable($A::TABELA);
$ativas = countElementsInTable($A::TABELA, ['is_active' => 1]);
$img = PluginAssinaturausuarioImagem::resumo();
$corpo = '<div class="assinaturausuario-numeros">'
    . '<div><strong>' . (int) $total . '</strong><span>pessoas com assinatura</span></div>'
    . '<div><strong>' . (int) $ativas . '</strong><span>assinaturas ligadas</span></div>'
    . '<div><strong>' . (int) $img['total'] . '</strong><span>imagens (' . $e(Toolbox::getSize($img['bytes'])) . ')</span></div>'
    . '</div>';
if (trim((string) $CFG_GLPI['url_base']) === '') {
    $corpo .= '<div class="assinaturausuario-alerta assinaturausuario-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>A URL do GLPI não está configurada (Configurar &gt; Geral). Sem ela, as imagens no formato "link" não aparecem nos e-mails.</span></div>';
}
$linhas = '';
foreach ($DB->request(['SELECT' => ['users_id', 'is_active', 'tipos', 'date_mod'], 'FROM' => $A::TABELA, 'ORDER' => 'date_mod DESC', 'LIMIT' => 200]) as $r) {
    $t = json_decode((string) $r['tipos'], true);
    $t = is_array($t) ? $t : array_keys($C::TIPOS);
    $nomes = array_map(fn($x) => $C::TIPOS[$x][0] ?? $x, array_filter($t));
    $linhas .= '<tr data-search="' . $e(mb_strtolower(getUserName((int) $r['users_id']))) . '"><td><a href="' . $e(User::getFormURLWithID((int) $r['users_id'])) . '&forcetab=PluginAssinaturausuarioPreferencia$1">' . $e(getUserName((int) $r['users_id'])) . '</a></td>'
        . '<td>' . ($r['is_active'] ? '<span class="assinaturausuario-selo assinaturausuario-selo-verde">Ligada</span>' : '<span class="assinaturausuario-selo">Desligada</span>') . '</td>'
        . '<td>' . $e(implode(', ', $nomes)) . '</td><td>' . $e(Html::convDateTime((string) $r['date_mod'])) . '</td></tr>';
}
$corpo .= '<input type="search" class="form-control form-control-sm assinaturausuario-busca" placeholder="Pesquisar pessoa..." data-assinaturausuario-busca-tabela>'
    . '<div class="table-responsive"><table class="table table-sm table-hover assinaturausuario-tabela"><thead><tr><th>Pessoa</th><th>Situação</th><th>Onde assina</th><th>Alterada em</th></tr></thead><tbody>'
    . ($linhas !== '' ? $linhas : '<tr><td colspan="4" class="assinaturausuario-pequeno">Ninguém cadastrou assinatura ainda.</td></tr>') . '</tbody></table></div>';
echo $card('ti ti-chart-bar', 'Situação', $corpo) . '</div>';

echo '</div>';
Html::footer();
